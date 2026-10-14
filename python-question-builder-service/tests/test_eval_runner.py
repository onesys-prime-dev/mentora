from app.schemas import GeneratedQuestion
from eval import metrics, run


def mc(text, correct=1, math="not_applicable"):
    return GeneratedQuestion(
        question_text=text,
        question_type="Multiple Choice",
        points=1,
        choices=[{"text": f"c{i}", "is_correct": i < correct} for i in range(4)],
        math_check=math,
    )


SOURCE = "Routers forward packets using the destination address in a routing table."


def test_golden_set_is_well_formed():
    cases = run.load_cases()
    ids = [c["id"] for c in cases]

    assert len(ids) == len(set(ids))

    for case in cases:
        assert case.get("source_text") or case.get("topic")
        run.request_for(case)  # must validate as a GenerateRequest


def test_schema_valid_counts_only_well_formed_questions():
    assert metrics.is_schema_valid(mc("Which device forwards packets?"))
    assert not metrics.is_schema_valid(mc("Two keys?", correct=2))


def test_duplicate_pairs_detects_near_copies():
    questions = [
        mc("What do routers use to forward packets across networks?"),
        mc("What do routers use to forward packets across networks"),
        mc("Name the pigment found in chloroplasts."),
    ]

    assert metrics.duplicate_pairs(questions) == 1


def test_grounded_fraction_none_without_source_and_scored_with_one():
    assert metrics.grounded_fraction([mc("Anything?")], None) is None

    fraction = metrics.grounded_fraction(
        [mc("What do routers use to forward packets?"), mc("Who painted the Mona Lisa famously?")],
        SOURCE,
    )

    assert fraction == 0.5


def test_runner_records_metrics_errors_and_uses_the_injected_clock():
    ticks = iter([0.0, 2.5, 10.0, 11.0])
    cases = [
        {"id": "ok", "kind": "text", "count": 2, "type_mix": {"multiple_choice": 2}, "source_text": SOURCE},
        {"id": "boom", "kind": "topic", "count": 1, "type_mix": {"multiple_choice": 1}, "topic": "x"},
    ]

    def generate(request):
        if request.topic == "x":
            raise RuntimeError("model down")

        return [mc("What do routers use to forward packets?", math="verified"), mc("Define a routing table.")], "fake-model"

    report = run.run_cases(cases, generate, clock=lambda: next(ticks))

    ok, boom = report["cases"]

    assert report["model"] == "fake-model"
    assert ok["produced"] == 2 and ok["seconds"] == 2.5 and ok["math_verified"] == 1
    assert boom["produced"] == 0 and "model down" in boom["error"]
    assert report["summary"]["requested"] == 3
    assert report["summary"]["fill_rate"] == round(2 / 3, 3)
