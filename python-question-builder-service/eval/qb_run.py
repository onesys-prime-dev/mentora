"""Evaluation of the worker pipeline against the golden set.

    python -m eval.qb_run --out eval/qb-report.json --ratings eval/qb-ratings.csv
    python -m eval.qb_run --only net-two-items
    python -m eval.qb_run --score-ratings eval/qb-ratings.csv

Runs each case through the same handler the worker runs, with the configured
model (the free allowance), so it is run by hand against a separate key and
never in per-PR CI. `--ratings` writes a sheet for a person to rate a sample;
`--score-ratings` averages a filled-in sheet.

Every prompt or pipeline change should be compared against the report of the
change before it."""

import argparse
import json
import time
from pathlib import Path
from typing import Callable

from . import qb_metrics

GOLDEN = Path(__file__).with_name("golden_qb.json")


class _Progress:
    """The handler reports progress through a context; here there is nobody to tell."""

    def progress(self, **fields) -> None:
        return None


def load_cases(path: Path = GOLDEN) -> list[dict]:
    return json.loads(path.read_text(encoding="utf-8"))["cases"]


def payload_for(case: dict) -> dict:
    """What PHP would send the worker for this case."""

    payload = {
        "count": case["count"],
        "type_mix": case["type_mix"],
        "items": case["items"],
        "digests": {},
        "existing_questions": case.get("existing_questions", []),
        "options": case.get("options", {}),
        "seed": 1,
    }

    if case.get("instruction"):
        payload["instruction"] = case["instruction"]

    return payload


def run_cases(
    cases: list[dict],
    handler: Callable[[dict, object], dict],
    clock: Callable[[], float] = time.monotonic,
) -> dict:
    """`handler(job, ctx) -> result`, the worker's own handler or a stand-in.
    A case that raises is a result, not a crash. The clock is an input so
    tests are deterministic."""

    rows = []
    sheet = []

    for case in cases:
        started = clock()

        try:
            result = handler({"payload": payload_for(case)}, _Progress())
            error = None
        except Exception as exc:  # noqa: BLE001 - a failed case is reported, not raised
            result, error = {"questions": []}, f"{type(exc).__name__}: {exc}"

        row = qb_metrics.case_report(case, result, clock() - started)
        row["error"] = error
        rows.append(row)
        sheet.extend(qb_metrics.rating_rows(case, result))

    return {"summary": qb_metrics.summarise(rows), "cases": rows, "rating_rows": sheet}


def main() -> None:  # pragma: no cover - needs a model and a key
    from qb import envfile
    from qb.config import build_provider, from_env
    from qb.governor import RateGovernor
    from qb.pipeline import make_generate_handler
    from qb.pool import ModelPool
    from qb.providers.openai_compat import GROQ_FREE_LIMITS

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", type=Path)
    parser.add_argument("--ratings", type=Path, help="write a sheet of the questions for a person to rate")
    parser.add_argument("--only", help="run a single case id")
    parser.add_argument("--score-ratings", type=Path, help="average a filled-in ratings sheet and exit")
    args = parser.parse_args()

    if args.score_ratings:
        print(json.dumps(qb_metrics.summarise_ratings(args.score_ratings.read_text(encoding="utf-8")), indent=2))

        return

    envfile.load()
    settings = from_env()
    pool = ModelPool(build_provider(settings), settings.models, RateGovernor({m: GROQ_FREE_LIMITS for m in settings.models}))

    cases = load_cases()

    if args.only:
        cases = [c for c in cases if c["id"] == args.only]

    report = run_cases(cases, make_generate_handler(pool))
    sheet = report.pop("rating_rows")

    if args.out:
        args.out.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")

    if args.ratings:
        args.ratings.write_text(qb_metrics.ratings_csv(sheet), encoding="utf-8")

    print(json.dumps(report["summary"], indent=2))


if __name__ == "__main__":  # pragma: no cover
    main()
