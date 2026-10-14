"""Deterministic gates: a question that fails any is dropped, never repaired.
Pure functions returning reason codes, so each rule is tested with a passing
and a failing case."""

import re
import statistics

from .questions import ESSAY, FILE_UPLOAD, MULTIPLE_CHOICE, SHORT_ANSWER, Question, normalise, overlap

DUPLICATE_OVERLAP = 0.75
SHORT_ANSWER_MAX_WORDS = 8
LENGTH_OUTLIER_RATIO = 2.5
LENGTH_OUTLIER_MIN_GAP = 30

_ALL_NONE = re.compile(r"\b(all|none|both|neither)\s+of\s+(the\s+)?(above|these|those)\b|\ball\s+the\s+above\b|\bnone\s+of\s+the\s+above\b", re.I)
_POSITIONAL = re.compile(r"\b(option|choice|answer|letter)\s+[a-d]\b|\b(the\s+)?(first|second|third|fourth|last)\s+(option|choice|answer)\b|\bthe\s+(above|previous)\s+(option|choice|answer|question)s?\b", re.I)


def check(question: Question, facts: dict[str, dict], seen_texts: list[str] | None = None) -> list[str]:
    """Reason codes for everything wrong with the question; empty means it passes."""

    reasons: list[str] = []

    if not question.text.strip():
        return ["empty_text"]

    if question.type == MULTIPLE_CHOICE:
        reasons += _multiple_choice(question)
    elif question.type == SHORT_ANSWER:
        reasons += _short_answer(question)
    elif question.type in (ESSAY, FILE_UPLOAD):
        if question.choices or question.accepted_answers:
            reasons.append("open_has_choices_or_key")
    else:
        reasons.append("unknown_type")

    if question.fact_id not in facts:
        reasons.append("ungrounded")

    if any(overlap(question.text, seen) >= DUPLICATE_OVERLAP for seen in (seen_texts or [])):
        reasons.append("duplicate")

    return reasons


def _multiple_choice(q: Question) -> list[str]:
    reasons = []
    texts = [c.text.strip() for c in q.choices]

    if len(texts) != 4 or any(not t for t in texts):
        return ["mc_choice_count"]

    if len({normalise(t) for t in texts}) != 4:
        reasons.append("mc_duplicate_choices")

    correct = [c for c in q.choices if c.is_correct]

    if len(correct) != 1:
        return reasons + ["mc_correct_count"]

    if any(_ALL_NONE.search(t) for t in texts):
        reasons.append("mc_all_none_above")

    if _POSITIONAL.search(q.text) or any(_POSITIONAL.search(t) for t in texts):
        reasons.append("mc_positional")

    answer = normalise(correct[0].text)

    if len(answer) >= 4 and answer in normalise(q.text):
        reasons.append("mc_answer_in_stem")

    others = [len(c.text.strip()) for c in q.choices if not c.is_correct]
    right = len(correct[0].text.strip())

    if others and right >= LENGTH_OUTLIER_RATIO * statistics.median(others) and right - max(others) >= LENGTH_OUTLIER_MIN_GAP:
        reasons.append("mc_length_outlier")

    return reasons


def _short_answer(q: Question) -> list[str]:
    keys = [a.strip() for a in q.accepted_answers if a.strip()]

    if not keys:
        return ["sa_empty_key"]

    reasons = []

    if any(len(k.split()) > SHORT_ANSWER_MAX_WORDS for k in keys):
        reasons.append("sa_key_too_long")

    first = normalise(keys[0])

    # A whole-word match, so a short acronym key given away in the stem counts.
    if len(first) >= 3 and f" {first} " in f" {normalise(q.text)} ":
        reasons.append("sa_answer_in_question")

    return reasons
