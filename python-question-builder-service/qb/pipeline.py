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
from .mathcheck import MISMATCH, VERIFIED, check_question
from .plan import Assignment, make_plan
from .pool import ModelPool
from .questions import MULTIPLE_CHOICE, Question
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
        if question.type == MULTIPLE_CHOICE:
            rng.shuffle(question.choices)


def to_payload(question: Question, model: str) -> dict:
    return {
        "type": question.type,
        "text": question.text,
        "choices": [{"text": c.text, "is_correct": c.is_correct} for c in question.choices],
        "accepted_answers": question.accepted_answers,
        "explanation": question.explanation,
        "evidence_quote": question.evidence,
        "page": question.page,
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
        topic_only = not payload.get("source_text")

        ctx.progress(stage="ingest", done=0, of=1, note="Reading the material")

        if topic_only:
            pages = ingest_mod.ingest_text(f"Topic: {payload.get('topic', '').strip()}").pages
        else:
            # `[[page N]]` lines from a PDF keep their numbers, so a fact can
            # say which page it came from; text with none is one page.
            text = str(payload["source_text"])
            pages = ingest_mod.split_marked(text) or ingest_mod.ingest_text(text).pages

        store = PayloadDigestStore(payload.get("digest"))
        needed = math.ceil(count * CONCEPT_MARGIN)

        ctx.progress(stage="digest", done=0, of=1, note="Finding the key ideas")
        digest = build_digest(pages, pool, store, needed_concepts=needed, rotation=int(payload.get("rotation", 0)))

        ctx.progress(stage="plan", done=0, of=count, note="Choosing what to ask about")
        plan = make_plan(digest.concepts(), count, type_mix, existing)
        facts = digest.facts()

        ctx.progress(stage="write", done=0, of=len(plan.assignments), note="Writing questions")
        written = write_questions(plan.assignments, facts, pool, seen_texts=existing)

        ctx.progress(stage="check", done=0, of=len(written.questions), note="Checking each answer")
        assignments = {a.concept["id"]: a for a in plan.assignments}
        checked = check_questions(written.questions, assignments, facts, pool, existing + [q.text for q in written.questions])

        shuffle_choices(checked.accepted, int(payload.get("seed", 0)))

        model = pool.models[0]
        flags = ["ungrounded"] if topic_only else []

        for question in checked.accepted:
            question.flags.extend(flags)

        dropped = Counter(written.dropped) + checked.dropped
        produced = len(checked.accepted)

        return {
            "questions": [to_payload(q, model) for q in checked.accepted],
            "digest": store.updated or store.cached,
            "stop_reason": "completed" if produced >= count else "exhausted_topic",
            "notes": plan.notes + digest.warnings,
            "diagnostics": {
                "requested": count,
                "produced": produced,
                "dropped": dict(dropped),
                "regenerated": checked.regenerated,
                "calls": digest.calls + written.calls + checked.calls,
                "tokens_in": digest.tokens_in + written.tokens_in + checked.tokens_in,
                "tokens_out": digest.tokens_out + written.tokens_out + checked.tokens_out,
                "prompt_version": PROMPT_VERSION,
            },
        }

    return handler
