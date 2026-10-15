"""Metrics for the worker pipeline (`qb`), pure so they can be tested with
canned job results and no model.

Everything here is a proxy that can be computed without a person: how many of
the questions asked for came back, whether each is tied to the material,
whether the second check agreed, repeats, and whether the shape asked for was
the shape written. What a proxy cannot tell is whether a stem is clear and the
wrong choices are good: that is the human sample (see `rating_rows` and
`summarise_ratings`)."""

import csv
import io

from qb.questions import content_words, overlap

# A question's cited fact counts as found in the material when this share of
# its words appear in the source text.
GROUNDING_OVERLAP = 0.6
DUPLICATE_OVERLAP = 0.75

RATING_COLUMNS = ("clarity", "distractors", "difficulty_fit", "keep")


def source_text(case: dict) -> str:
    return "\n".join(str(i.get("text", "")) for i in case.get("items", []))


def question_kind(question: dict) -> str:
    """The type key the question was asked as (multiple_answer, true_false, ...)."""

    kind = question.get("type", "")

    if kind != "Multiple Choice":
        return kind.lower().replace(" ", "_")

    if question.get("allow_multiple_answers"):
        return "multiple_answer"

    texts = sorted(str(c.get("text", "")).strip().lower() for c in question.get("choices", []))

    return "true_false" if texts == ["false", "true"] else "multiple_choice"


def grounded_share(questions: list[dict], source: str) -> float | None:
    if not questions or not source.strip():
        return None

    words = content_words(source)
    found = 0

    for question in questions:
        evidence = content_words(str(question.get("evidence_quote", "")))

        if evidence and len(evidence & words) / len(evidence) >= GROUNDING_OVERLAP:
            found += 1

    return found / len(questions)


def verified_share(questions: list[dict]) -> float | None:
    if not questions:
        return None

    checked = [q for q in questions if (q.get("verification") or {}).get("solver_agrees") is not None]

    if not checked:
        return None

    return sum(1 for q in checked if q["verification"]["solver_agrees"] is True) / len(checked)


def duplicate_pairs(questions: list[dict]) -> int:
    pairs = 0

    for index, question in enumerate(questions):
        for other in questions[index + 1 :]:
            if overlap(str(question.get("text", "")), str(other.get("text", ""))) >= DUPLICATE_OVERLAP:
                pairs += 1

    return pairs


def type_fidelity(questions: list[dict], type_mix: dict[str, int]) -> float | None:
    """The share of the questions asked for, by type, that came back as that type."""

    asked = sum(type_mix.values())

    if asked <= 0:
        return None

    produced: dict[str, int] = {}

    for question in questions:
        kind = question_kind(question)
        produced[kind] = produced.get(kind, 0) + 1

    matched = sum(min(count, produced.get(kind, 0)) for kind, count in type_mix.items())

    return matched / asked


def choice_conformance(questions: list[dict], choices: int) -> float | None:
    """Of the single-answer multiple choice questions, the share with the asked-for number of choices."""

    pool = [q for q in questions if question_kind(q) == "multiple_choice"]

    if not pool:
        return None

    return sum(1 for q in pool if len(q.get("choices", [])) == choices) / len(pool)


def case_report(case: dict, result: dict, seconds: float = 0.0) -> dict:
    questions = list(result.get("questions", []))
    diagnostics = result.get("diagnostics", {})
    requested = int(case["count"])
    source = source_text(case)

    return {
        "id": case["id"],
        "kind": case.get("kind", ""),
        "requested": requested,
        "produced": len(questions),
        "fill_rate": len(questions) / requested if requested else 0.0,
        "grounded": grounded_share(questions, source),
        "verified": verified_share(questions),
        "duplicate_pairs": duplicate_pairs(questions),
        "type_fidelity": type_fidelity(questions, case.get("type_mix", {})),
        "choice_conformance": choice_conformance(questions, int((case.get("options") or {}).get("choices", 4))),
        "dropped": dict(diagnostics.get("dropped", {})),
        "topped_up": int(diagnostics.get("topped_up", 0)),
        "tokens": int(diagnostics.get("tokens_in", 0)) + int(diagnostics.get("tokens_out", 0)),
        "calls": int(diagnostics.get("calls", 0)),
        "stop_reason": result.get("stop_reason", ""),
        "seconds": round(seconds, 2),
    }


def summarise(rows: list[dict]) -> dict:
    def mean(key: str) -> float | None:
        values = [r[key] for r in rows if r.get(key) is not None]

        return round(sum(values) / len(values), 3) if values else None

    dropped: dict[str, int] = {}

    for row in rows:
        for reason, count in row.get("dropped", {}).items():
            dropped[reason] = dropped.get(reason, 0) + count

    return {
        "cases": len(rows),
        "requested": sum(r["requested"] for r in rows),
        "produced": sum(r["produced"] for r in rows),
        "fill_rate": mean("fill_rate"),
        "grounded": mean("grounded"),
        "verified": mean("verified"),
        "duplicate_pairs": sum(r["duplicate_pairs"] for r in rows),
        "type_fidelity": mean("type_fidelity"),
        "choice_conformance": mean("choice_conformance"),
        "tokens": sum(r["tokens"] for r in rows),
        "dropped": dropped,
        "cases_short": [r["id"] for r in rows if r["produced"] < r["requested"]],
    }


# --------------------------------------------------------------- the human sample


def rating_rows(case: dict, result: dict) -> list[dict]:
    """One row per question for a reviewer to fill in. Scores are 1 to 5;
    `keep` is yes or no."""

    rows = []

    for number, question in enumerate(result.get("questions", []), start=1):
        correct = "; ".join(str(c["text"]) for c in question.get("choices", []) if c.get("is_correct"))
        choices = " | ".join(str(c["text"]) for c in question.get("choices", []))

        rows.append({
            "case": case["id"],
            "n": number,
            "type": question_kind(question),
            "question": question.get("text", ""),
            "choices": choices,
            "answer": correct or "; ".join(question.get("accepted_answers", [])),
            "evidence": question.get("evidence_quote", ""),
            **{column: "" for column in RATING_COLUMNS},
        })

    return rows


def ratings_csv(rows: list[dict]) -> str:
    out = io.StringIO()
    writer = csv.DictWriter(out, fieldnames=["case", "n", "type", "question", "choices", "answer", "evidence", *RATING_COLUMNS])
    writer.writeheader()
    writer.writerows(rows)

    return out.getvalue()


def summarise_ratings(text: str) -> dict:
    """Averages of a filled-in sheet. Rows a reviewer left blank are ignored,
    so a partly rated sheet still gives a result."""

    rows = list(csv.DictReader(io.StringIO(text)))
    out: dict = {"rated": 0}
    scores: dict[str, list[int]] = {c: [] for c in RATING_COLUMNS if c != "keep"}
    keep = []

    for row in rows:
        if not any((row.get(c) or "").strip() for c in RATING_COLUMNS):
            continue

        out["rated"] += 1

        for column in scores:
            value = (row.get(column) or "").strip()

            if value.isdigit() and 1 <= int(value) <= 5:
                scores[column].append(int(value))

        verdict = (row.get("keep") or "").strip().lower()

        if verdict in ("yes", "y", "no", "n"):
            keep.append(verdict in ("yes", "y"))

    for column, values in scores.items():
        out[column] = round(sum(values) / len(values), 2) if values else None

    out["keep_rate"] = round(sum(keep) / len(keep), 3) if keep else None
    out["low_scores"] = sum(1 for column in scores.values() for v in column if v <= 2)

    return out
