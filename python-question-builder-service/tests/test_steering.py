"""True/False, multiple-answer and a chosen number of choices."""

from qb import gates, pipeline, verify
from qb.plan import split_counts
from qb.questions import MULTIPLE_ANSWER, MULTIPLE_CHOICE, TRUE_FALSE, Choice, Question
from qb.write import build_prompt
from qb.plan import Assignment

FACTS = {"f1": {"id": "f1", "text": "Jupiter is the largest planet in the solar system."}}


def mc(type_, texts, correct, count=4, text="Which statement is supported by the reading?"):
    return Question(
        type=type_,
        text=text,
        choices=[Choice(t, i in correct) for i, t in enumerate(texts)],
        fact_id="f1",
        choice_count=count,
    )


def test_a_true_false_question_needs_exactly_true_and_false():
    ok = mc(TRUE_FALSE, ["True", "False"], {0}, count=2, text="Jupiter is the largest planet.")

    assert gates.check(ok, FACTS) == []
    assert "tf_shape" in gates.check(mc(TRUE_FALSE, ["Yes", "No"], {0}, count=2), FACTS)
    assert "tf_correct_count" in gates.check(mc(TRUE_FALSE, ["True", "False"], {0, 1}, count=2), FACTS)


def test_the_choice_count_asked_for_is_the_one_required():
    three = mc(MULTIPLE_CHOICE, ["Mars", "Jupiter", "Venus"], {1}, count=3)

    assert gates.check(three, FACTS) == []
    assert "mc_choice_count" in gates.check(mc(MULTIPLE_CHOICE, ["Mars", "Jupiter", "Venus"], {1}, count=4), FACTS)


def test_a_multiple_answer_question_needs_two_or_three_right_and_wrong_ones_left():
    good = mc(MULTIPLE_ANSWER, ["Jupiter", "Saturn", "Mercury", "Venus", "Earth"], {0, 1}, count=5, text="Which are gas giants?")

    assert gates.check(good, FACTS) == []
    assert "ma_correct_count" in gates.check(mc(MULTIPLE_ANSWER, ["a1", "b2", "c3", "d4"], {0}, count=4), FACTS)
    assert "ma_correct_count" in gates.check(mc(MULTIPLE_ANSWER, ["a1", "b2", "c3", "d4"], {0, 1, 2}, count=4), FACTS)


def test_the_solver_must_find_exactly_the_marked_choices_for_a_multiple_answer_question():
    q = mc(MULTIPLE_ANSWER, ["Jupiter", "Saturn", "Mercury", "Venus"], {0, 1}, text="Which are gas giants?")

    assert verify.judge(q, {"choice": "A", "supported": ["A", "B"]}).ok
    assert verify.judge(q, {"choice": "A", "supported": ["A"]}).reason == "solver_disagrees"
    assert verify.judge(q, {"choice": "A", "supported": ["A", "B", "C"]}).reason == "solver_disagrees"


def test_a_true_false_question_is_checked_like_a_choice_question():
    q = mc(TRUE_FALSE, ["True", "False"], {0}, count=2)

    assert verify.needs_verification(q)
    assert verify.judge(q, {"choice": "A", "supported": ["A"]}).ok
    assert verify.judge(q, {"choice": "B", "supported": ["B"]}).reason == "solver_disagrees"


def test_new_type_keys_take_part_in_the_split():
    assert split_counts(6, {"true_false": 2, "multiple_answer": 1, "multiple_choice": 3}) == {
        TRUE_FALSE: 2,
        MULTIPLE_ANSWER: 1,
        MULTIPLE_CHOICE: 3,
    }


def test_the_payload_stores_both_new_shapes_as_multiple_choice():
    tf = pipeline.to_payload(mc(TRUE_FALSE, ["True", "False"], {1}, count=2), "m")
    ma = pipeline.to_payload(mc(MULTIPLE_ANSWER, ["a1", "b2", "c3", "d4"], {0, 2}), "m")
    one = pipeline.to_payload(mc(MULTIPLE_CHOICE, ["a1", "b2", "c3", "d4"], {0}), "m")

    assert tf["type"] == "Multiple Choice" and tf["allow_multiple_answers"] is False
    assert ma["type"] == "Multiple Choice" and ma["allow_multiple_answers"] is True and ma["answers_count"] == 2
    assert one["allow_multiple_answers"] is False and one["answers_count"] is None


def test_the_requested_choice_count_is_kept_in_range():
    assert pipeline.choice_count_from({}) == 4
    assert pipeline.choice_count_from({"options": {"choices": 3}}) == 3
    assert pipeline.choice_count_from({"options": {"choices": 9}}) == 5
    assert pipeline.choice_count_from({"options": {"choices": "x"}}) == 4


def test_true_false_keeps_true_first_after_shuffling():
    q = mc(TRUE_FALSE, ["True", "False"], {0}, count=2)

    pipeline.shuffle_choices([q], 7)

    assert [c.text for c in q.choices] == ["True", "False"]


def test_the_prompt_states_the_number_of_choices():
    concept = {"id": "c1", "name": "Planets", "facts": [FACTS["f1"]]}
    prompt = build_prompt([Assignment(concept, MULTIPLE_CHOICE, FACTS["f1"])], [], "", 5)

    assert "exactly 5 choices" in prompt
