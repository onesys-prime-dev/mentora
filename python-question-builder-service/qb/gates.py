"""Deterministic gates: a question that fails any is dropped, never repaired.
Pure functions returning reason codes, so each rule is tested with a passing
and a failing case."""

import re
import statistics

from .questions import (
    ESSAY,
    FILE_UPLOAD,
    MULTIPLE_ANSWER,
    MULTIPLE_CHOICE,
    SHORT_ANSWER,
    TRUE_FALSE,
    Question,
    content_words,
    normalise,
    overlap,
)

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
        reasons += _multiple_choice(question, facts)
    elif question.type == MULTIPLE_ANSWER:
        reasons += _multiple_choice(question, facts, several=True)
    elif question.type == TRUE_FALSE:
        reasons += _true_false(question)
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


# A wrong choice made only of words from one source fact is another true
# statement, not a wrong answer: the question then has two right choices.
DISTRACTOR_MIN_WORDS = 5
DISTRACTOR_RESTATES = 0.85
_NEGATIONS = {"not", "no", "never", "cannot", "without", "neither", "nor", "none", "unable"}


def _restates(choice_words: set[str], fact_words: set[str]) -> bool:
    """Nearly all of the choice's words are the fact's, and it does not turn
    the fact around with a negation the fact does not have."""

    if len(choice_words & fact_words) / len(choice_words) < DISTRACTOR_RESTATES:
        return False

    return (choice_words & _NEGATIONS) <= (fact_words & _NEGATIONS)


def _true_false(q: Question) -> list[str]:
    """Exactly two choices, True and False, one of them right."""

    texts = {normalise(c.text) for c in q.choices}

    if len(q.choices) != 2 or texts != {"true", "false"}:
        return ["tf_shape"]

    if sum(1 for c in q.choices if c.is_correct) != 1:
        return ["tf_correct_count"]

    return []


def _multiple_choice(q: Question, facts: dict[str, dict], several: bool = False) -> list[str]:
    reasons = []
    texts = [c.text.strip() for c in q.choices]
    wanted = q.choice_count

    if len(texts) != wanted or any(not t for t in texts):
        return ["mc_choice_count"]

    if len({normalise(t) for t in texts}) != wanted:
        reasons.append("mc_duplicate_choices")

    correct = [c for c in q.choices if c.is_correct]

    # One right choice, or (several) two or more with at least two wrong ones left.
    if several:
        if not 2 <= len(correct) <= wanted - 2:
            return reasons + ["ma_correct_count"]
    elif len(correct) != 1:
        return reasons + ["mc_correct_count"]

    if any(_ALL_NONE.search(t) for t in texts):
        reasons.append("mc_all_none_above")

    if _POSITIONAL.search(q.text) or any(_POSITIONAL.search(t) for t in texts):
        reasons.append("mc_positional")

    fact_words = [content_words(f["text"]) for f in facts.values()]

    for choice in q.choices:
        if choice.is_correct:
            continue

        words = content_words(choice.text)

        if len(words) >= DISTRACTOR_MIN_WORDS and any(_restates(words, known) for known in fact_words):
            reasons.append("mc_distractor_is_a_fact")
            break

    answer = normalise(correct[0].text)

    if len(answer) >= 4 and answer in normalise(q.text):
        reasons.append("mc_answer_in_stem")

    if several:
        return reasons

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
