"""Pure metrics over generated questions, so the runner can be unit-tested
with canned outputs and no model."""

from app import generation

# A question counts as "grounded" in the source when at least this share of
# its content words appear in the source. A lexical proxy: it does not prove a
# fact is stated, it catches questions about things the source never mentions.
GROUNDING_OVERLAP = 0.5


def is_schema_valid(question) -> bool:
    return bool(question.question_text.strip()) and generation._is_structurally_valid(question)


def duplicate_pairs(questions) -> int:
    seen: list[str] = []
    pairs = 0

    for question in questions:
        text = generation._normalize_for_dedupe(question.question_text)

        if generation._is_too_similar(text, seen):
            pairs += 1

        seen.append(text)

    return pairs


def grounded_fraction(questions, source_text: str | None) -> float | None:
    """None when there is no source (topic-only), which cannot be grounded."""

    if not source_text or not questions:
        return None

    source_words = generation._content_words(source_text)
    grounded = 0

    for question in questions:
        words = generation._content_words(question.question_text)

        if words and len(words & source_words) / len(words) >= GROUNDING_OVERLAP:
            grounded += 1

    return grounded / len(questions)


def estimate_tokens(text: str) -> int:
    return generation._estimate_tokens(text)


def case_metrics(case: dict, questions, prompt_text: str, seconds: float) -> dict:
    valid = sum(1 for q in questions if is_schema_valid(q))
    output_text = " ".join(q.model_dump_json() for q in questions)

    return {
        "id": case["id"],
        "kind": case["kind"],
        "requested": case["count"],
        "produced": len(questions),
        "schema_valid": valid,
        "duplicate_pairs": duplicate_pairs(questions),
        "grounded_fraction": grounded_fraction(questions, case.get("source_text")),
        "math_mismatch": sum(1 for q in questions if q.math_check == "mismatch"),
        "math_verified": sum(1 for q in questions if q.math_check == "verified"),
        # Estimates from character counts (about 4 per token); the hosted
        # providers report real usage, which replaces these in S3.
        "tokens_in_estimate": estimate_tokens(prompt_text),
        "tokens_out_estimate": estimate_tokens(output_text),
        "seconds": round(seconds, 2),
    }


def summarise(rows: list[dict]) -> dict:
    produced = sum(r["produced"] for r in rows)
    requested = sum(r["requested"] for r in rows)
    grounded = [r["grounded_fraction"] for r in rows if r["grounded_fraction"] is not None]

    return {
        "cases": len(rows),
        "requested": requested,
        "produced": produced,
        "fill_rate": round(produced / requested, 3) if requested else 0.0,
        "schema_valid_rate": round(sum(r["schema_valid"] for r in rows) / produced, 3) if produced else 0.0,
        "duplicate_pairs": sum(r["duplicate_pairs"] for r in rows),
        "grounded_fraction": round(sum(grounded) / len(grounded), 3) if grounded else None,
        "math_mismatch": sum(r["math_mismatch"] for r in rows),
        "tokens_in_estimate": sum(r["tokens_in_estimate"] for r in rows),
        "tokens_out_estimate": sum(r["tokens_out_estimate"] for r in rows),
        "seconds": round(sum(r["seconds"] for r in rows), 2),
    }
