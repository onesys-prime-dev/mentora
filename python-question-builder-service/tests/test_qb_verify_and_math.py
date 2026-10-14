import json

import pytest

from qb import mathcheck
from qb.governor import RateGovernor
from qb.pipeline import check_questions, make_generate_handler, shuffle_choices, to_payload
from qb.plan import Assignment
from qb.pool import ModelPool
from qb.providers.base import Capabilities
from qb.providers.fake import FakeProvider
from qb.questions import Choice, Question
from qb.verify import judge, parse_answers, verify

# ------------------------------------------------------------------ math


@pytest.mark.parametrize(
    "spec,expected",
    [
        ({"expression": "2*x + 3 - 11", "variable": "x", "expected": 4}, "verified"),
        ({"expression": "2x + 3 - 11", "variable": "x", "expected": 4}, "verified"),
        ({"expression": "2*x + 3 - 11", "variable": "x", "expected": 5}, "mismatch"),
        ({"expression": "x**2 - 9", "variable": "x", "expected": -3}, "verified"),
        ({"expression": "3*4 + 2", "expected": 14}, "verified"),
        ({"expression": "3*4 + 2", "expected": 15}, "mismatch"),
        ({"expression": "sqrt(16) + 1", "expected": 5}, "verified"),
        ({"expression": "x**2 + 1", "variable": "x", "expected": 0}, "not_applicable"),
        ({"expression": "", "expected": 1}, "not_applicable"),
        ({"expression": "1+1"}, "not_applicable"),
        ({"expression": "1+1", "expected": "two"}, "not_applicable"),
        ({"expression": "y + 1", "variable": "x", "expected": 1}, "not_applicable"),
        ({"expression": "__import__('os').system('x')", "expected": 0}, "not_applicable"),
        ({"expression": "open('f')", "expected": 0}, "not_applicable"),
        ({"expression": "1/0", "expected": 0}, "not_applicable"),
        (None, "not_applicable"),
        ("2+2=4", "not_applicable"),
    ],
)
def test_spec_is_computed_not_trusted(spec, expected):
    assert mathcheck.check_spec(spec) == expected


def test_a_right_spec_with_a_wrong_key_is_a_mismatch():
    spec = {"expression": "2*x + 3 - 11", "variable": "x", "expected": 4}

    assert mathcheck.check_question(spec, "x = 4") == "verified"
    assert mathcheck.check_question(spec, "x = 5") == "mismatch"
    assert mathcheck.check_question(spec, "four") == "not_applicable"
    assert mathcheck.check_question(spec, None) == "verified"


# ------------------------------------------------------------------ verify


def mc(text="Which device forwards packets?", correct=0, **kw):
    names = ["Router", "Switch hub", "Modem unit", "Cable run"]

    return Question("Multiple Choice", text, "c1", [Choice(n, i == correct) for i, n in enumerate(names)],
                    evidence="Routers forward packets.", fact_id="f1", **kw)


def sa(text="Which protocol is reliable?", key="TCP"):
    return Question("Short Answer", text, "c2", accepted_answers=[key], evidence="TCP is reliable.", fact_id="f2")


def make_pool(script):
    provider = FakeProvider(script)
    caps = Capabilities(tokens_per_minute=10**9, tokens_per_day=10**12)

    return provider, ModelPool(provider, ["m"], RateGovernor({"m": caps}), clock=lambda: 0.0, sleep=lambda s: None)


def answers(*items):
    return json.dumps({"answers": [dict(n=i, **a) for i, a in enumerate(items, start=1)]})


def test_judge_accepts_agreement_and_refuses_disagreement_ambiguity_and_silence():
    assert judge(mc(), {"choice": "a"}).ok
    assert judge(mc(), {"choice": "B"}).reason == "solver_disagrees"
    assert judge(mc(), {"choice": "A", "ambiguous": True}).reason == "ambiguous"
    assert judge(mc(), None).reason == "no_answer"
    assert judge(sa(), {"answer": "tcp"}).ok
    assert judge(sa(key="Transmission Control Protocol"), {"answer": "the transmission control protocol"}).ok
    assert not judge(sa(), {"answer": "UDP"}).ok


def test_verification_is_blind_and_batched():
    provider, pool = make_pool([answers({"choice": "A"}, {"answer": "TCP"})])

    result = verify([mc(), sa(), Question("Essay", "Discuss.", "c3")], pool)

    sent = provider.calls[0]["user"]
    assert [v.ok for v in result.verdicts] == [True, True, True]
    assert result.calls == 1
    assert "correct" not in sent.lower() and "is_correct" not in sent
    assert "Fact: Routers forward packets." in sent


def test_unreadable_verifier_output_fails_closed():
    _, pool = make_pool(["garbage"])

    assert [v.reason for v in verify([mc()], pool).verdicts] == ["no_answer"]


def test_parse_answers_tolerates_noise():
    assert parse_answers('noise {"answers":[{"n":1,"choice":"A"}]} tail')[1]["choice"] == "A"
    assert parse_answers("nothing") == {}


# ------------------------------------------------------------------ check


FACTS = {"f1": {"id": "f1", "text": "Routers forward packets.", "page": 1},
         "f2": {"id": "f2", "text": "TCP is reliable.", "page": 2}}
CONCEPT = {"id": "c1", "name": "Routing", "facts": [FACTS["f1"]]}


def regen_item(**kw):
    base = {"concept_id": "c1", "type": "Multiple Choice", "text": "What forwards packets across networks?",
            "fact_id": "f1", "explanation": "x",
            "choices": [{"text": "Router", "correct": True}, {"text": "Switch hub", "correct": False},
                        {"text": "Modem unit", "correct": False}, {"text": "Cable run", "correct": False}]}
    base.update(kw)

    return base


def test_a_wrong_key_is_regenerated_once_and_kept_when_the_second_try_verifies():
    _, pool = make_pool([
        answers({"choice": "B"}),                      # first verification disagrees
        json.dumps({"questions": [regen_item()]}),     # regeneration
        answers({"choice": "A"}),                      # second verification agrees
    ])

    result = check_questions([mc()], {"c1": Assignment(CONCEPT, "Multiple Choice")}, FACTS, pool, [])

    assert len(result.accepted) == 1 and result.regenerated == 1
    assert result.accepted[0].verification["regenerated"] is True
    assert result.dropped == {}


def test_a_second_disagreement_drops_the_question_with_its_reason():
    _, pool = make_pool([
        answers({"choice": "B"}),
        json.dumps({"questions": [regen_item()]}),
        answers({"choice": "C"}),
    ])

    result = check_questions([mc()], {"c1": Assignment(CONCEPT, "Multiple Choice")}, FACTS, pool, [])

    assert result.accepted == [] and result.dropped["solver_disagrees"] == 1


def test_a_computed_mismatch_is_dropped_without_spending_a_verification_call():
    provider, pool = make_pool([])
    wrong = mc(math_spec={"expression": "2*x + 3 - 11", "variable": "x", "expected": 5})

    result = check_questions([wrong], {}, FACTS, pool, [])

    assert result.dropped["math_mismatch"] == 1 and provider.calls == []


def test_a_verified_numeric_question_keeps_its_math_result():
    _, pool = make_pool([answers({"choice": "A"})])
    right = mc(math_spec={"expression": "2*x + 3 - 11", "variable": "x", "expected": 4})
    right.choices[0].text = "x = 4"

    result = check_questions([right], {}, FACTS, pool, [])

    assert result.accepted[0].verification["math_check"] == "verified"


# ------------------------------------------------------------------ finish


def test_the_key_follows_its_choice_through_the_shuffle_and_the_shuffle_is_seeded():
    def build():
        return [mc() for _ in range(6)]

    one, two, other = build(), build(), build()
    shuffle_choices(one, seed=7)
    shuffle_choices(two, seed=7)
    shuffle_choices(other, seed=8)

    for question in one:
        assert [c.text for c in question.choices if c.is_correct] == ["Router"]

    assert [[c.text for c in q.choices] for q in one] == [[c.text for c in q.choices] for q in two]
    assert [[c.text for c in q.choices] for q in one] != [[c.text for c in q.choices] for q in other]
    assert any([c.text for c in q.choices][0] != "Router" for q in one)


# ------------------------------------------------------------------ the job


class Ctx:
    def __init__(self):
        self.stages = []

    def progress(self, **fields):
        self.stages.append(fields["stage"])


SOURCE = "Routers forward packets using the destination address in a routing table. TCP gives reliable ordered delivery with acknowledgements."


def digest_reply():
    return json.dumps({"concepts": [
        {"name": "Routing", "facts": [{"text": "Routers forward packets using the destination address", "page": 1}]},
        {"name": "TCP", "facts": [{"text": "TCP gives reliable ordered delivery with acknowledgements", "page": 1}]},
    ]})


def test_the_whole_job_runs_the_stages_in_order_and_returns_questions_digest_and_diagnostics():
    write_reply = json.dumps({"questions": [
        {"concept_id": "0.0", "type": "Multiple Choice", "text": "Which device forwards packets between networks?",
         "fact_id": "0.0.0", "explanation": "Routers route.",
         "choices": [{"text": "Router", "correct": True}, {"text": "Switch hub", "correct": False},
                     {"text": "Modem unit", "correct": False}, {"text": "Cable run", "correct": False}]},
        {"concept_id": "0.1", "type": "Short Answer", "text": "Which protocol gives reliable delivery?",
         "fact_id": "0.1.0", "explanation": "TCP.", "accepted_answers": ["TCP"]},
    ]})
    _, pool = make_pool([digest_reply(), write_reply, answers({"choice": "A"}, {"answer": "TCP"})])
    ctx = Ctx()

    result = make_generate_handler(pool)(
        {"payload": {"source_text": SOURCE, "count": 2, "type_mix": {"multiple_choice": 1, "short_answer": 1}, "seed": 3}}, ctx
    )

    assert ctx.stages == ["ingest", "digest", "plan", "write", "check"]
    assert result["stop_reason"] == "completed"
    assert len(result["questions"]) == 2
    assert result["questions"][0]["evidence_quote"].startswith("Routers forward")
    assert result["digest"]["sections"]
    assert result["diagnostics"]["calls"] == 3 and result["diagnostics"]["dropped"] == {}
    assert result["questions"][0]["verification"]["solver_agrees"] is True


def test_a_short_supply_is_reported_as_exhausted_and_a_topic_only_job_is_flagged_ungrounded():
    reply = json.dumps({"concepts": [{"name": "Databases", "facts": [{"text": "Topic: relational databases", "page": 1}]}]})
    write_reply = json.dumps({"questions": [
        {"concept_id": "0.0", "type": "Short Answer", "text": "What organises data into tables?", "fact_id": "0.0.0",
         "explanation": "x", "accepted_answers": ["relational database"]}]})
    _, pool = make_pool([reply, write_reply, answers({"answer": "relational database"})])

    result = make_generate_handler(pool)(
        {"payload": {"topic": "relational databases", "count": 3, "type_mix": {"short_answer": 3}}}, Ctx()
    )

    assert result["stop_reason"] == "exhausted_topic"
    assert result["questions"][0]["flags"] == ["ungrounded"]
    assert any("distinct ideas" in n for n in result["notes"])


def test_payload_shape_never_exposes_a_correct_flag_name_the_php_side_does_not_expect():
    q = mc()
    out = to_payload(q, "model-x")

    assert out["choices"][0] == {"text": "Router", "is_correct": True}
    assert out["model_used"] == "model-x" and out["prompt_version"].startswith("write-")
