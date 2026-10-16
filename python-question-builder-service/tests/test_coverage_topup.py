"""Coverage in the result, salvage of a cut-off reply, and the top-up."""

import json

from qb.pipeline import make_generate_handler
from qb.write import parse_questions

from tests.test_qb_items import PDF, PDF_REPLY, Ctx, make_pool


def question(concept, fact, text, answer):
    return {"concept_id": concept, "type": "Short Answer", "text": text, "fact_id": fact,
            "explanation": "x", "accepted_answers": [answer]}


def test_the_complete_questions_of_a_cut_off_reply_are_kept():
    full = json.dumps({"questions": [question("c1", "f1", "First question about TCP?", "TCP"),
                                     question("c2", "f2", "Second question about UDP?", "UDP")]})
    cut = full[: full.rindex('{"concept_id"') + 40]  # stops inside the second question

    assert [q["text"] for q in parse_questions(cut)] == ["First question about TCP?"]


def test_a_whole_reply_is_read_as_before_and_rubbish_gives_nothing():
    whole = json.dumps({"questions": [question("c1", "f1", "Only question here?", "x")]})

    assert len(parse_questions(whole)) == 1
    assert parse_questions("no json at all") == []
    assert parse_questions('{"questions": [') == []


def test_a_question_that_cannot_be_checked_is_made_up_from_a_fact_never_used():
    first = json.dumps({"questions": [question("i0.0.0", "i0.0.0.0", "Which protocol gives reliable ordered delivery?", "TCP")]})
    disagree = json.dumps({"answers": [{"n": 1, "answer": "UDP"}]})
    # The same concept written again, and checked again: dropped.
    again = json.dumps({"questions": [question("i0.0.0", "i0.0.0.0", "Name the protocol with acknowledgements for delivery.", "TCP")]})
    # Then the fact never used is asked about.
    more = json.dumps({"questions": [question("i0.0.1", "i0.0.1.0", "Which transport protocol is faster but unreliable?", "UDP")]})
    agree = json.dumps({"answers": [{"n": 1, "answer": "UDP"}]})

    _, pool = make_pool([PDF_REPLY, first, disagree, again, disagree, more, agree])

    result = make_generate_handler(pool)({"payload": {"items": [PDF], "count": 1, "type_mix": {"short_answer": 1}}}, Ctx())

    assert [q["text"] for q in result["questions"]] == ["Which transport protocol is faster but unreliable?"]
    assert result["diagnostics"]["topped_up"] == 1
    assert result["stop_reason"] == "completed"


def test_coverage_counts_questions_per_selected_item():
    first = json.dumps({"questions": [question("i0.0.0", "i0.0.0.0", "Which protocol gives reliable ordered delivery?", "TCP")]})
    agree = json.dumps({"answers": [{"n": 1, "answer": "TCP"}]})
    _, pool = make_pool([PDF_REPLY, first, agree])

    result = make_generate_handler(pool)({"payload": {"items": [PDF], "count": 1, "type_mix": {"short_answer": 1}}}, Ctx())

    assert result["diagnostics"]["coverage"] == [{"item_id": 12, "title": "Transport slides", "count": 1}]
    assert result["diagnostics"]["topped_up"] == 0

