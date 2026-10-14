"""Plan: which concept each question is about, and of which type.

One concept per question, taken from the digest, so two questions on the same
idea are prevented by construction rather than repaired afterwards. Concepts
the quiz already covers are left out. When the material runs out of distinct
concepts the plan is shorter than asked and says so."""

from dataclasses import dataclass, field

from .questions import TYPE_KEYS, overlap

# A concept counts as already covered when an existing question shares this
# much of its wording with one of the concept's facts.
COVERED_OVERLAP = 0.6


@dataclass
class Assignment:
    concept: dict
    type: str


@dataclass
class Plan:
    assignments: list[Assignment]
    requested: int
    skipped_covered: int = 0
    notes: list[str] = field(default_factory=list)

    @property
    def shortfall(self) -> int:
        return self.requested - len(self.assignments)


def split_counts(count: int, type_mix: dict[str, int]) -> dict[str, int]:
    """Largest-remainder split of `count` across the requested types."""

    relevant = {k: v for k, v in type_mix.items() if k in TYPE_KEYS and v > 0}

    if not relevant:
        return {TYPE_KEYS["multiple_choice"]: count}

    total = sum(relevant.values())
    shares = {k: v / total * count for k, v in relevant.items()}
    floors = {k: int(s) for k, s in shares.items()}
    order = sorted(relevant, key=lambda k: (shares[k] - floors[k], k), reverse=True)

    for key in order[: count - sum(floors.values())]:
        floors[key] += 1

    return {TYPE_KEYS[k]: n for k, n in floors.items() if n > 0}


def is_covered(concept: dict, existing_questions: list[str]) -> bool:
    return any(
        overlap(question, fact["text"]) >= COVERED_OVERLAP
        for question in existing_questions
        for fact in concept["facts"]
    )


def make_plan(concepts: list[dict], count: int, type_mix: dict[str, int], existing_questions: list[str] | None = None) -> Plan:
    existing = existing_questions or []
    fresh = [c for c in concepts if not is_covered(c, existing)]
    skipped = len(concepts) - len(fresh)

    types: list[str] = []

    for type_label, n in split_counts(count, type_mix).items():
        types.extend([type_label] * n)

    take = min(count, len(fresh))
    assignments = [Assignment(concept, types[i]) for i, concept in enumerate(fresh[:take])]

    notes = []

    if take < count:
        notes.append(f"The material has {take} distinct ideas to ask about, fewer than the {count} asked for.")

    return Plan(assignments, count, skipped, notes)
