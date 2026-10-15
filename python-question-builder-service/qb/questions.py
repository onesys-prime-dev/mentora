"""The question shape every stage after the digest passes around."""

import re
from dataclasses import dataclass, field

MULTIPLE_CHOICE = "Multiple Choice"
SHORT_ANSWER = "Short Answer"
ESSAY = "Essay"
FILE_UPLOAD = "File Upload"

TYPE_KEYS = {
    "multiple_choice": MULTIPLE_CHOICE,
    "short_answer": SHORT_ANSWER,
    "essay": ESSAY,
    "file_upload": FILE_UPLOAD,
}

_WORD = re.compile(r"[a-z0-9']+")
_STOP = {"the", "a", "an", "of", "and", "or", "to", "in", "is", "are", "was", "it", "its", "for", "on", "by", "as", "at", "that", "this", "with", "what", "which", "who", "how", "does", "do"}


def content_words(text: str) -> set[str]:
    return {w for w in _WORD.findall(text.lower()) if len(w) > 1 and w not in _STOP}


def normalise(text: str) -> str:
    return " ".join(_WORD.findall(text.lower()))


def overlap(a: str, b: str) -> float:
    """Overlap coefficient of content words: shared / the smaller set."""

    wa, wb = content_words(a), content_words(b)

    if not wa or not wb:
        return 0.0

    return len(wa & wb) / min(len(wa), len(wb))


@dataclass
class Choice:
    text: str
    is_correct: bool = False


@dataclass
class Question:
    type: str
    text: str
    concept_id: str = ""
    choices: list[Choice] = field(default_factory=list)
    accepted_answers: list[str] = field(default_factory=list)
    explanation: str = ""
    fact_id: str = ""
    evidence: str = ""  # the digest fact's text, filled from fact_id, never from the model
    # Every fact of the concept, so a check can tell a wrong choice from another true statement.
    context_facts: list[str] = field(default_factory=list)
    page: int | None = None
    math_spec: dict | None = None
    flags: list[str] = field(default_factory=list)
    verification: dict = field(default_factory=dict)
