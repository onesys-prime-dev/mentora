"""The evaluation of the worker pipeline: golden set shape, metrics and the
rating sheet, all with canned results and no model."""

import json
from pathlib import Path

from eval import qb_metrics, qb_run

SOURCE = "Routers forward packets using the destination address in a routing table. TCP offers reliable ordered delivery."
CASE = {
    "id": "c1", "kind": "x", "count": 4,
    "type_mix": {"multiple_choice": 2, "true_false": 1, "multiple_answer": 1},
    "options": {"choices": 4},
    "items": [{"id": 1, "title": "Net", "type": "reading", "text": SOURCE}],
}


def mc(text, evidence, agrees=True, n=4):
    return {"type": "Multiple Choice", "text": text, "allow_multiple_answers": False,
            "choices": [{"text": f"c{i}", "is_correct": i == 0} for i in range(n)],
            "accepted_answers": [], "evidence_quote": evidence, "verification": {"solver_agrees": agrees}}


def tf(text):
    return {"type": "Multiple Choice", "text": text, "allow_multiple_answers": False,
            "choices": [{"text": "True", "is_correct": True}, {"text": "False", "is_correct": False}],
            "evidence_quote": "TCP offers reliable ordered delivery", "verification": {"solver_agrees": True}}


def ma(text):
    return {"type": "Multiple Choice", "text": text, "allow_multiple_answers": True,
            "choices": [{"text": f"c{i}", "is_correct": i < 2} for i in range(4)],
            "evidence_quote": "routers forward packets", "verification": {"solver_agrees": True}}


RESULT = {
    "questions": [
        mc("What do routers use to forward packets?", "Routers forward packets using the destination address"),
        mc("Which protocol gives reliable delivery?", "TCP offers reliable ordered delivery", agrees=False),
        tf("TCP is reliable."),
        ma("Which statements about routers hold?"),
    ],
    "diagnostics": {"dropped": {"solver_disagrees": 2}, "topped_up": 1, "tokens_in": 900, "tokens_out": 300, "calls": 7},
    "stop_reason": "completed",
}


def test_the_golden_set_is_well_formed():
    cases = qb_run.load_cases()
    ids = [c["id"] for c in cases]

    assert len(ids) == len(set(ids)) >= 12

    for case in cases:
        assert case["items"] and all(i["text"].strip() for i in case["items"])
        assert sum(case["type_mix"].values()) == case["count"], case["id"]
        assert qb_run.payload_for(case)["count"] == case["count"]


def test_a_complete_result_scores_fully():
    row = qb_metrics.case_report(CASE, RESULT)

    assert row["fill_rate"] == 1.0
    assert row["grounded"] == 1.0
    assert row["type_fidelity"] == 1.0
    assert row["choice_conformance"] == 1.0
    assert row["verified"] == 0.75
    assert row["tokens"] == 1200 and row["topped_up"] == 1


def test_a_short_wrong_shaped_result_is_visible():
    result = {"questions": [mc("What do routers use?", "Routers forward packets using the destination address", n=3)],
              "diagnostics": {}}

    row = qb_metrics.case_report(CASE, result)

    assert row["fill_rate"] == 0.25
    assert row["type_fidelity"] == 0.25
    assert row["choice_conformance"] == 0.0


def test_evidence_that_is_not_in_the_material_counts_as_ungrounded():
    result = {"questions": [mc("Q one about something?", "Mitochondria produce energy for the cell")]}

    assert qb_metrics.case_report(CASE, result)["grounded"] == 0.0


def test_repeats_are_counted():
    result = {"questions": [mc("What do routers use to forward packets across networks?", "x"),
                            mc("What do routers use to forward packets across networks", "x")]}

    assert qb_metrics.duplicate_pairs(result["questions"]) == 1


def test_the_runner_reports_a_failed_case_instead_of_crashing():
    def handler(job, ctx):
        if job["payload"]["count"] == 4:
            raise RuntimeError("provider down")

        return RESULT

    other = dict(CASE, id="c2", count=4)
    report = qb_run.run_cases([CASE, other], handler, clock=iter([0.0, 1.0, 1.0, 3.0]).__next__)

    assert [c["error"] for c in report["cases"]] == ["RuntimeError: provider down"] * 2
    assert report["summary"]["produced"] == 0
    assert report["summary"]["cases_short"] == ["c1", "c2"]


def test_the_runner_collects_summary_and_a_rating_sheet():
    report = qb_run.run_cases([CASE], lambda job, ctx: RESULT, clock=iter([0.0, 2.0]).__next__)

    assert report["summary"]["fill_rate"] == 1.0
    assert report["summary"]["dropped"] == {"solver_disagrees": 2}
    assert len(report["rating_rows"]) == 4
    assert report["cases"][0]["seconds"] == 2.0


def test_a_rating_sheet_round_trips_and_ignores_blank_rows():
    rows = qb_metrics.rating_rows(CASE, RESULT)
    sheet = qb_metrics.ratings_csv(rows)

    assert sheet.splitlines()[0].startswith("case,n,type,question")

    # Two questions rated, one left blank.
    filled = qb_metrics.summarise_ratings(
        "case,n,type,question,choices,answer,evidence,clarity,distractors,difficulty_fit,keep\n"
        "c1,1,multiple_choice,Q1,a|b,a,e,5,4,3,yes\n"
        "c1,2,multiple_choice,Q2,a|b,a,e,2,1,3,no\n"
        "c1,3,true_false,Q3,,,e,,,,\n"
    )

    assert filled["rated"] == 2
    assert filled["clarity"] == 3.5
    assert filled["distractors"] == 2.5
    assert filled["keep_rate"] == 0.5
    assert filled["low_scores"] == 2


def test_the_golden_file_is_valid_utf8_json():
    json.loads(Path(qb_run.GOLDEN).read_text(encoding="utf-8"))


def test_the_real_handler_accepts_a_golden_style_payload_end_to_end():
    from qb.pipeline import make_generate_handler
    from tests.test_qb_items import READING, READING_REPLY, make_pool

    write = json.dumps({"questions": [
        {"concept_id": "i0.0.0", "type": "Short Answer", "text": "What do routers use to forward packets?",
         "fact_id": "i0.0.0.0", "explanation": "Routing table.", "accepted_answers": ["destination address"]}]})
    verify = json.dumps({"answers": [{"n": 1, "answer": "destination address"}]})
    _, pool = make_pool([READING_REPLY, write, verify])
    case = {"id": "smoke", "kind": "smoke", "count": 1, "type_mix": {"short_answer": 1}, "items": [READING]}

    report = qb_run.run_cases([case], make_generate_handler(pool))

    assert report["cases"][0]["error"] is None
    assert report["summary"]["produced"] == 1
    assert report["cases"][0]["grounded"] == 1.0
    assert report["cases"][0]["type_fidelity"] == 1.0
