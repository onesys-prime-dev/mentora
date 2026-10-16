"""The generate job, start to finish: ingest, digest, plan, write, check,
finish. Each stage reports progress so the tray can say what is happening and
the job can be resumed or cancelled between stages.

check = math (sympy) then blind verification, one regeneration of whatever
failed, then drop. finish = shuffle the choices (seeded, after verification,
because models favour B and C)."""

import math
import random
from collections import Counter
from dataclasses import dataclass, field

from . import ingest as ingest_mod
from .digest import PayloadDigestStore, build_digest
from .items import digest_items
from .mathcheck import MISMATCH, VERIFIED, check_question
from .plan import Assignment, make_plan
from .pool import ModelPool
from .questions import (
    CHOICE_COUNT_DEFAULT,
    CHOICE_COUNT_RANGE,
    MULTIPLE_ANSWER,
    MULTIPLE_CHOICE,
    TRUE_FALSE,
    Question,
)
from .verify import needs_verification, verify
from .write import PROMPT_VERSION, write_questions

# Ask the digest for a few more concepts than questions, so gates and
# verification that drop a question still leave something to write from.
CONCEPT_MARGIN = 1.3


@dataclass
class CheckResult:
    accepted: list[Question] = field(default_factory=list)
    dropped: Counter = field(default_factory=Counter)
    tokens_in: int = 0
    tokens_out: int = 0
    calls: int = 0
    regenerated: int = 0


def _math(question: Question) -> str:
    if not question.math_spec:
        return "not_applicable"

    correct = next((c.text for c in question.choices if c.is_correct), None) if question.type == MULTIPLE_CHOICE else None

    return check_question(question.math_spec, correct)


def check_questions(
    questions: list[Question],
    assignments: dict[str, Assignment],
    facts: dict[str, dict],
    pool: ModelPool,
    seen_texts: list[str],
) -> CheckResult:
    out = CheckResult()
    survivors: list[Question] = []

    for question in questions:
        verdict = _math(question)
        question.verification["math_check"] = verdict

        if verdict == MISMATCH:
            out.dropped["math_mismatch"] += 1
        else:
            survivors.append(question)

    first = verify(survivors, pool)
    _count(out, first)

    failed: list[Question] = []

    for question, verdict in zip(survivors, first.verdicts):
        if verdict.ok:
            _accept(out, question)
        else:
            question.verification["first_reason"] = verdict.reason
            failed.append(question)

    if not failed:
        return out

    # One regeneration: the same concept and type, written again.
    again = [assignments[q.concept_id] for q in failed if q.concept_id in assignments]
    out.regenerated = len(again)

    if again:
        rewritten = write_questions(again, facts, pool, seen_texts=seen_texts)
        out.tokens_in += rewritten.tokens_in
        out.tokens_out += rewritten.tokens_out
        out.calls += rewritten.calls

        redo = []

        for question in rewritten.questions:
            question.verification["regenerated"] = True
            verdict = _math(question)
            question.verification["math_check"] = verdict

            if verdict == MISMATCH:
                out.dropped["math_mismatch"] += 1
            else:
                redo.append(question)

        second = verify(redo, pool)
        _count(out, second)

        for question, verdict in zip(redo, second.verdicts):
            if verdict.ok:
                _accept(out, question)
            else:
                out.dropped[verdict.reason or "solver_disagrees"] += 1

        if len(again) > len(rewritten.questions):
            out.dropped["regeneration_failed_gates"] += len(again) - len(rewritten.questions)
    else:
        for question in failed:
            out.dropped[question.verification.get("first_reason", "solver_disagrees")] += 1

    return out


def _count(out: CheckResult, result) -> None:
    out.tokens_in += result.tokens_in
    out.tokens_out += result.tokens_out
    out.calls += result.calls


def _accept(out: CheckResult, question: Question) -> None:
    question.verification["grounded"] = True
    question.verification["solver_agrees"] = True if needs_verification(question) else None
    out.accepted.append(question)


def shuffle_choices(questions: list[Question], seed: int) -> None:
    """Shuffles after verification; the key travels with its choice because
    correctness is a property of the choice, not of its position."""

    rng = random.Random(seed)

    for question in questions:
        # True/False keeps True first; every other choice question is shuffled.
        if question.type in (MULTIPLE_CHOICE, MULTIPLE_ANSWER):
            rng.shuffle(question.choices)


def choice_count_from(payload: dict) -> int:
    """How many choices the teacher asked for, kept in range."""

    try:
        value = int((payload.get("options") or {}).get("choices", CHOICE_COUNT_DEFAULT))
    except (TypeError, ValueError):
        return CHOICE_COUNT_DEFAULT

    low, high = CHOICE_COUNT_RANGE

    return max(low, min(high, value))


def to_payload(question: Question, model: str) -> dict:
    correct = sum(1 for c in question.choices if c.is_correct)
    several = question.type == MULTIPLE_ANSWER

    return {
        # True/False and Multiple Answer are multiple choice in the quiz.
        "type": MULTIPLE_CHOICE if question.type in (TRUE_FALSE, MULTIPLE_ANSWER) else question.type,
        "allow_multiple_answers": several,
        "answers_count": correct if several else None,
        "text": question.text,
        "choices": [{"text": c.text, "is_correct": c.is_correct} for c in question.choices],
        "accepted_answers": question.accepted_answers,
        "explanation": question.explanation,
        "evidence_quote": question.evidence,
        "page": question.page,
        "source_title": question.source_title,
        "source_item_id": question.source_item_id,
        "math_check": question.verification.get("math_check", "not_applicable"),
        "verification": question.verification,
        "flags": question.flags,
        "prompt_version": PROMPT_VERSION,
        "model_used": model,
    }


def make_generate_handler(pool: ModelPool):
    """The job handler for kind `generate`."""

    def handler(job: dict, ctx) -> dict:
        payload = job.get("payload") or {}
        count = max(1, int(payload.get("count", 10)))
        type_mix = payload.get("type_mix") or {"multiple_choice": count}
        existing = list(payload.get("existing_questions") or [])
        items = [i for i in (payload.get("items") or []) if isinstance(i, dict)]
        topic_only = not items and not payload.get("source_text")
        needed = math.ceil(count * CONCEPT_MARGIN)
        rotation = int(payload.get("rotation", 0))

        ctx.progress(stage="ingest", done=0, of=1, note="Reading the material")

        extra: dict = {}

        if items:
            # The selected learning items, each digested and cached on its own.
            def reading(index: int, total: int, title: str) -> None:
                ctx.progress(stage="digest", done=index, of=total, note=f"Reading {title}")

            many = digest_items(
                items, dict(payload.get("digests") or {}), pool,
                needed_facts=needed, rotation=rotation, on_progress=reading,
            )
            concepts, facts = many.concepts, many.facts
            warnings, calls = many.warnings, many.calls
            digest_in, digest_out = many.tokens_in, many.tokens_out
            extra = {
                "digests": many.digests,
                "per_item": [vars(s) for s in many.stats],
            }
        else:
            if topic_only:
                pages = ingest_mod.ingest_text(f"Topic: {payload.get('topic', '').strip()}").pages
            else:
                # `[[page N]]` lines from a PDF keep their numbers, so a fact can
                # say which page it came from; text with none is one page.
                text = str(payload["source_text"])
                pages = ingest_mod.split_marked(text) or ingest_mod.ingest_text(text).pages

            store = PayloadDigestStore(payload.get("digest"))

            ctx.progress(stage="digest", done=0, of=1, note="Finding the key ideas")
            digest = build_digest(pages, pool, store, needed_facts=needed, rotation=rotation)
            concepts, facts = digest.concepts(), digest.facts()
            warnings, calls = digest.warnings, digest.calls
            digest_in, digest_out = digest.tokens_in, digest.tokens_out
            extra = {"digest": store.updated or store.cached}

        ctx.progress(stage="plan", done=0, of=count, note="Choosing what to ask about")
        plan = make_plan(concepts, count, type_mix, existing)

        ctx.progress(stage="write", done=0, of=len(plan.assignments), note="Writing questions")
        written = write_questions(
            plan.assignments, facts, pool, seen_texts=existing,
            instruction=str(payload.get("instruction") or ""),
            choice_count=choice_count_from(payload),
        )

        ctx.progress(stage="check", done=0, of=len(written.questions), note="Checking each answer")
        assignments = {a.concept["id"]: a for a in plan.assignments}
        checked = check_questions(written.questions, assignments, facts, pool, existing + [q.text for q in written.questions])

        # Short of the number asked for: write the rest from facts that were
        # never used, once. A question that failed its check is not retried
        # here (its fact already produced one); only unused facts are.
        topped_up = 0

        if len(checked.accepted) < count:
            used = {a.fact["id"] for a in plan.assignments if a.fact}
            spare = []

            for concept in concepts:
                rest = [f for f in concept["facts"] if f["id"] not in used]

                if rest:
                    spare.append({**concept, "facts": rest})

            missing = count - len(checked.accepted)
            seen = existing + [q.text for q in checked.accepted]
            more_plan = make_plan(spare, missing, type_mix, seen) if spare else None

            if more_plan is not None and more_plan.assignments:
                ctx.progress(stage="write", done=0, of=len(more_plan.assignments), note="Writing a few more questions")
                more_written = write_questions(
                    more_plan.assignments, facts, pool, seen_texts=seen,
                    instruction=str(payload.get("instruction") or ""),
                    choice_count=choice_count_from(payload),
                )
                more_by_concept = {a.concept["id"]: a for a in more_plan.assignments}
                more_checked = check_questions(
                    more_written.questions, more_by_concept, facts, pool, seen + [q.text for q in more_written.questions]
                )

                topped_up = len(more_checked.accepted)
                checked.accepted.extend(more_checked.accepted)
                checked.dropped.update(more_checked.dropped)
                checked.tokens_in += more_written.tokens_in + more_checked.tokens_in
                checked.tokens_out += more_written.tokens_out + more_checked.tokens_out
                checked.calls += more_written.calls + more_checked.calls
                written.dropped.update(more_written.dropped)

        shuffle_choices(checked.accepted, int(payload.get("seed", 0)))

        model = pool.models[0]
        flags = ["ungrounded"] if topic_only else []

        for question in checked.accepted:
            question.flags.extend(flags)

        dropped = Counter(written.dropped) + checked.dropped
        produced = len(checked.accepted)

        by_item: Counter = Counter(q.source_item_id for q in checked.accepted if q.source_item_id is not None)
        coverage = [
            {"item_id": item.get("id"), "title": str(item.get("title", "")), "count": by_item.get(item.get("id"), 0)}
            for item in items
        ]

        diagnostics = {
            "requested": count,
            "produced": produced,
            "topped_up": topped_up,
            "coverage": coverage,
            "dropped": dict(dropped),
            "regenerated": checked.regenerated,
            "calls": calls + written.calls + checked.calls,
            "tokens_in": digest_in + written.tokens_in + checked.tokens_in,
            "tokens_out": digest_out + written.tokens_out + checked.tokens_out,
            "prompt_version": PROMPT_VERSION,
        }

        if "per_item" in extra:
            diagnostics["per_item"] = extra.pop("per_item")

        return {
            "questions": [to_payload(q, model) for q in checked.accepted],
            **extra,
            "stop_reason": "completed" if produced >= count else "exhausted_topic",
            "notes": plan.notes + warnings,
            "diagnostics": diagnostics,
        }

    return handler
