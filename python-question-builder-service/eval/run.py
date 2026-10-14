"""Manual evaluation runner.

    python -m eval.run --out eval/report.json

Runs the golden set through the service's own generator and prints the
metrics. It uses the configured model (the free allowance, once hosted), so it
is run by hand against a separate key and never in per-PR CI.
"""

import argparse
import json
import time
from pathlib import Path
from typing import Callable

from app.schemas import GenerateRequest

from . import metrics

GOLDEN = Path(__file__).with_name("golden.json")


def load_cases(path: Path = GOLDEN) -> list[dict]:
    return json.loads(path.read_text(encoding="utf-8"))["cases"]


def request_for(case: dict) -> GenerateRequest:
    return GenerateRequest(
        topic=case.get("topic"),
        source_text=case.get("source_text"),
        count=case["count"],
        type_mix=case["type_mix"],
    )


def run_cases(
    cases: list[dict],
    generate: Callable,
    clock: Callable[[], float] = time.monotonic,
) -> dict:
    """`generate(request) -> (questions, model_name)`; the clock is an input
    so tests are deterministic."""

    rows = []
    model = None

    for case in cases:
        request = request_for(case)
        started = clock()

        try:
            questions, model = generate(request)
            error = None
        except Exception as exc:  # a failed case is a result, not a crash
            questions, error = [], f"{type(exc).__name__}: {exc}"

        prompt = case.get("source_text") or case.get("topic") or ""
        row = metrics.case_metrics(case, questions, prompt, clock() - started)
        row["error"] = error
        rows.append(row)

    return {"model": model, "summary": metrics.summarise(rows), "cases": rows}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", type=Path, default=None)
    parser.add_argument("--only", help="run a single case id")
    args = parser.parse_args()

    from app import generation

    cases = load_cases()

    if args.only:
        cases = [c for c in cases if c["id"] == args.only]

    report = run_cases(cases, generation.generate_questions)

    if args.out:
        args.out.write_text(json.dumps(report, indent=2), encoding="utf-8")

    print(json.dumps(report["summary"], indent=2))


if __name__ == "__main__":
    main()
