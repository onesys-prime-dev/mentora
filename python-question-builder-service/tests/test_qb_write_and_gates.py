import json

import pytest

from qb import gates
from qb.governor import RateGovernor
from qb.plan import make_plan, split_counts
from qb.pool import ModelPool
from qb.providers.base import Capabilities
from qb.providers.fake import FakeProvider
from qb.questions import Choice, Question
from qb.write import build_prompt, write_questions

FACTS = {
    "0.0.0": {"id": "0.0.0", "text": "Routers forward packets using the destination address in a routing table.", "page": 4},
    "0.1.0": {"id": "0.1.0", "text": "TCP gives reliable ordered delivery with acknowledgements.", "page": 6},
}


def mc(text="Which device forwards packets between networks?", choices=None, fact="0.0.0", **kw):
    choices = choices or [("Router", True), ("Switch hub", False), ("Modem unit", False), ("Cable run", False)]

    return Question("Multiple Choice", text, "0.0", [Choice(t, c) for t, c in choices], fact_id=fact, **kw)


def codes(question, seen=None):
    return gates.check(question, FACTS, seen)


def test_a_good_multiple_choice_passes():
    assert codes(mc()) == []


@pytest.mark.parametrize(
    "question,expected",
    [
        (mc(choices=[("Router", True), ("Switch", False), ("Modem", False)]), "mc_choice_count"),
        (mc(choices=[("Router", True), ("Switch", False), ("Modem", False), ("", False)]), "mc_choice_count"),
        (mc(choices=[("Router", True), ("router", False), ("Modem", False), ("Cable", False)]), "mc_duplicate_choices"),
        (mc(choices=[("Router", True), ("Switch", True), ("Modem", False), ("Cable", False)]), "mc_correct_count"),
        (mc(choices=[("Router", False), ("Switch", False), ("Modem", False), ("Cable", False)]), "mc_correct_count"),
        (mc(choices=[("Router", True), ("Switch", False), ("Modem", False), ("All of the above", False)]), "mc_all_none_above"),
        (mc(choices=[("Router", True), ("Switch", False), ("Modem", False), ("None of the above", False)]), "mc_all_none_above"),
        (mc(text="Is option B the device that forwards packets?"), "mc_positional"),
        (mc(text="A router forwards packets between networks. Which device does so: router?"), "mc_answer_in_stem"),
        (mc(choices=[("A router, which reads the destination address of every packet it receives", True), ("Hub", False), ("Modem", False), ("Cable", False)]), "mc_length_outlier"),
        (mc(fact="9.9.9"), "ungrounded"),
        (mc(text="  "), "empty_text"),
    ],
)
def test_each_multiple_choice_rule_catches_its_failure(question, expected):
    assert expected in codes(question)


def test_short_answer_rules():
    ok = Question("Short Answer", "Which protocol gives reliable delivery?", "0.1", accepted_answers=["TCP"], fact_id="0.1.0")

    assert codes(ok) == []
    assert codes(Question("Short Answer", "Name it?", "0.1", accepted_answers=[], fact_id="0.1.0")) == ["sa_empty_key"]
    assert "sa_key_too_long" in codes(Question("Short Answer", "Name it?", "0.1", accepted_answers=["one two three four five six seven eight nine"], fact_id="0.1.0"))
    assert "sa_answer_in_question" in codes(Question("Short Answer", "Why does TCP matter for TCP?", "0.1", accepted_answers=["TCP"], fact_id="0.1.0"))


def test_open_questions_carry_no_choices_or_key():
    assert codes(Question("Essay", "Discuss routing.", "0.0", fact_id="0.0.0")) == []
    assert "open_has_choices_or_key" in codes(Question("Essay", "Discuss routing.", "0.0", accepted_answers=["x"], fact_id="0.0.0"))
    assert "open_has_choices_or_key" in codes(Question("File Upload", "Upload a diagram.", "0.0", choices=[Choice("a")], fact_id="0.0.0"))


def test_unknown_type_is_refused():
    assert "unknown_type" in codes(Question("Matching", "Match these.", "0.0", fact_id="0.0.0"))


def test_duplicate_of_an_existing_question_is_dropped_but_a_different_one_is_not():
    seen = ["Which device forwards packets between networks?"]

    assert "duplicate" in codes(mc(), seen)
    assert "duplicate" not in codes(mc(text="What does TCP acknowledge during delivery?", fact="0.1.0"), seen)


# ------------------------------------------------------------------ the plan


CONCEPTS = [
    {"id": "0.0", "name": "Routing", "facts": [FACTS["0.0.0"]]},
    {"id": "0.1", "name": "TCP", "facts": [FACTS["0.1.0"]]},
    {"id": "0.2", "name": "UDP", "facts": [{"id": "0.2.0", "text": "UDP is faster but unreliable.", "page": 7}]},
]


def test_counts_follow_the_mix_and_sum_to_the_total():
    assert split_counts(10, {"multiple_choice": 6, "short_answer": 3, "essay": 1}) == {
        "Multiple Choice": 6, "Short Answer": 3, "Essay": 1,
    }
    assert sum(split_counts(7, {"multiple_choice": 1, "short_answer": 1, "essay": 1}).values()) == 7
    assert split_counts(4, {}) == {"Multiple Choice": 4}


def test_each_question_gets_its_own_concept_and_a_short_supply_is_reported():
    plan = make_plan(CONCEPTS, 5, {"multiple_choice": 2, "short_answer": 1})

    assert len(plan.assignments) == 3 and plan.shortfall == 2
    assert len({a.concept["id"] for a in plan.assignments}) == 3
    assert "3 distinct ideas" in plan.notes[0]


def test_concepts_the_quiz_already_covers_are_left_out():
    plan = make_plan(CONCEPTS, 3, {"multiple_choice": 3}, ["Which device uses the destination address in a routing table to forward packets?"])

    assert plan.skipped_covered == 1
    assert "Routing" not in [a.concept["name"] for a in plan.assignments]


# ------------------------------------------------------------------ writing


def make_pool(script):
    provider = FakeProvider(script)
    caps = Capabilities(tokens_per_minute=10**9, tokens_per_day=10**12)

    return provider, ModelPool(provider, ["m"], RateGovernor({"m": caps}), clock=lambda: 0.0, sleep=lambda s: None)


def item(concept, qtype, text, fact, **kw):
    return {"concept_id": concept, "type": qtype, "text": text, "fact_id": fact, "explanation": "because", **kw}


GOOD_MC = item("0.0", "Multiple Choice", "Which device forwards packets between networks?", "0.0.0",
               choices=[{"text": "Router", "correct": True}, {"text": "Switch hub", "correct": False},
                        {"text": "Modem unit", "correct": False}, {"text": "Cable run", "correct": False}])
GOOD_SA = item("0.1", "Short Answer", "Which protocol gives reliable delivery?", "0.1.0", accepted_answers=["TCP"])


def test_good_questions_are_kept_with_evidence_and_page_from_the_digest():
    plan = make_plan(CONCEPTS, 2, {"multiple_choice": 1, "short_answer": 1})
    provider, pool = make_pool([json.dumps({"questions": [GOOD_MC, GOOD_SA]})])

    result = write_questions(plan.assignments, FACTS, pool)

    assert len(result.questions) == 2 and result.calls == 1
    first = result.questions[0]
    assert first.evidence.startswith("Routers forward") and first.page == 4
    assert len(provider.calls) == 1


def test_a_forged_citation_a_wrong_concept_and_failed_gates_are_dropped_with_reasons():
    plan = make_plan(CONCEPTS, 2, {"multiple_choice": 2})
    forged = dict(GOOD_MC, fact_id="7.7.7")
    elsewhere = item("0.2", "Short Answer", "What is UDP like?", "0.2.0", accepted_answers=["unreliable"])
    bad = item("0.1", "Multiple Choice", "Pick one?", "0.1.0", choices=[{"text": "a", "correct": True}])
    _, pool = make_pool([json.dumps({"questions": [forged, elsewhere, bad]})])

    result = write_questions(plan.assignments, FACTS, pool)

    assert result.questions == []
    assert result.dropped["ungrounded"] == 1
    assert result.dropped["wrong_concept"] == 1
    assert result.dropped["mc_choice_count"] == 1


def test_two_questions_that_repeat_each_other_keep_only_the_first():
    plan = make_plan(CONCEPTS, 2, {"multiple_choice": 2})
    twin = dict(GOOD_MC, concept_id="0.1", fact_id="0.1.0")
    _, pool = make_pool([json.dumps({"questions": [GOOD_MC, twin]})])

    result = write_questions(plan.assignments, FACTS, pool)

    assert len(result.questions) == 1 and result.dropped["duplicate"] == 1


def test_unreadable_output_yields_nothing_and_assignments_are_written_five_at_a_time():
    many = [dict(id=f"c{i}", name=f"N{i}", facts=[FACTS["0.0.0"]]) for i in range(7)]
    plan = make_plan(many, 7, {"multiple_choice": 7})
    provider, pool = make_pool(["nonsense", "{}"])

    result = write_questions(plan.assignments, FACTS, pool)

    assert result.questions == [] and result.calls == 2 and len(provider.calls) == 2


def test_the_prompt_carries_the_facts_and_the_avoid_list_but_the_facts_are_data():
    plan = make_plan(CONCEPTS, 1, {"multiple_choice": 1})

    prompt = build_prompt(plan.assignments, ["An earlier question about routers?"])

    assert "[0.0.0]" in prompt and "earlier question" in prompt
