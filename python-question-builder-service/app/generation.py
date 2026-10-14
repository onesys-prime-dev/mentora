import json
import os
import re
import threading

from . import config, verification
from .schemas import GenerateRequest, GeneratedQuestion

_generation_lock = threading.Lock()


class GenerationBusyError(Exception):
    """Raised when a generation job is already running on this machine's GPU."""


class GenerationFailedError(Exception):
    """Raised when the model could not produce usable structured output."""


# Deliberately short. The response *shape* is enforced structurally by the
# JSON schema passed to Ollama's `format` (XGrammar-constrained decoding),
# so spending prompt tokens restating the schema — and an example of it —
# just costs prefill time on every single call. Only behaviour the grammar
# can't express belongs here.
SYSTEM_PROMPT = """You write original assessment questions for an online course.

Rules:
1. If reference source material is given, use it ONLY to understand the topic,
   key concepts and difficulty level. NEVER quote or closely paraphrase it.
   Every question must be original, not a reproduction of the source's wording.
2. Multiple Choice: exactly 4 choices, exactly one with is_correct true.
   Distractors must be plausible, not obviously wrong.
3. Short Answer and Essay: leave choices empty.
   Short Answer must have one short, unambiguous answer (a word, number or
   short phrase). Put it first in accepted_answers, then up to 2 equivalent
   forms (acronym, full term, alternate spelling). Other types: leave
   accepted_answers empty.
4. Never repeat a question listed as already used, and never write a
   near-duplicate of one — the same underlying concept asked a different
   way still counts as a repeat. Every question in this batch must cover a
   distinct concept.
5. Never write a question whose answer is restated in the question itself."""

# Maps the type keys the PHP side sends to the labels the app's question
# schema uses (and that the grammar will constrain output to).
TYPE_LABELS = {
    "multiple_choice": "Multiple Choice",
    "short_answer": "Short Answer",
    "essay": "Essay",
    "file_upload": "File Upload",
}

# Overlap coefficient (|A∩B| / min(|A|,|B|), on the *content words* of two
# question texts — see _content_words) above which two questions are
# treated as duplicates. Measured empirically against this domain's actual
# output: character-level string similarity (the first approach here) was
# fooled constantly by this app's templated question stems — "what does
# the acronym RAM stand for" vs "...CPU..." scored 0.94 on raw character
# overlap despite being about entirely different things, while genuine
# near-duplicates scored similarly. Comparing content words after
# stripping the shared boilerplate (see _CONTENT_STOPWORDS) fixes that:
# RAM vs CPU shares 2 of 3 content words at best (~0.67), safely under
# this threshold, while real duplicates ("primary function of a computer"
# vs "common function of a computer") still score high.
DUPLICATE_SIMILARITY_THRESHOLD = 0.75

# How many extra rounds a single type's sub-batch gets to replace questions
# rejected as duplicates or structurally broken (see _is_structurally_valid)
# before giving up and returning fewer than requested. Under-delivering is
# always safer than shipping a duplicate or an ungradable question.
#
# Kept at 1, not higher: measured directly (dedupe debug log) that a 2nd
# retry on a topic this model has already exhausted mostly reproduces the
# exact same rejected questions again, even with temperature escalated to
# 1.1 — a narrow topic pushed past this model's genuine repertoire (maybe
# ~20-30 distinct concepts for something like "Introduction to Computing")
# hits a hard ceiling no amount of retrying manufactures around. A 3rd
# attempt cost real time for close to zero yield in that case.
MAX_DEDUPE_RETRIES = 1


def _build_response_schema(count: int, type_label: str) -> dict:
    """
    JSON schema handed to Ollama's `format`. minItems/maxItems on the outer
    array structurally guarantee the requested question count; locking
    `question_type` to a single enum value (one call per type — see
    _split_count_by_type_mix) means `choices` can be constrained exactly
    too: 4-and-only-4 for Multiple Choice, none at all otherwise. Neither
    was true before this was a single shared schema across mixed types.
    """

    is_multiple_choice = type_label == "Multiple Choice"

    choices_schema = {
        "type": "array",
        "items": {
            "type": "object",
            "properties": {
                "text": {"type": "string"},
                "is_correct": {"type": "boolean"},
            },
            "required": ["text", "is_correct"],
        },
    }

    if is_multiple_choice:
        choices_schema["minItems"] = 4
        choices_schema["maxItems"] = 4
    else:
        choices_schema["maxItems"] = 0

    # Short Answer gets an answer key the app can auto-grade against: the
    # expected answer plus up to 2 equivalent forms. No other type has one.
    accepted_answers_schema = {"type": "array", "items": {"type": "string"}}

    if type_label == "Short Answer":
        accepted_answers_schema["minItems"] = 1
        accepted_answers_schema["maxItems"] = 3
    else:
        accepted_answers_schema["maxItems"] = 0

    return {
        "type": "array",
        "minItems": count,
        "maxItems": count,
        "items": {
            "type": "object",
            "properties": {
                "question_text": {"type": "string"},
                "question_type": {"type": "string", "enum": [type_label]},
                "points": {"type": "number"},
                "is_required": {"type": "boolean"},
                "choices": choices_schema,
                "accepted_answers": accepted_answers_schema,
            },
            "required": [
                "question_text",
                "question_type",
                "points",
                "is_required",
                "choices",
                "accepted_answers",
            ],
        },
    }


def _split_count_by_type_mix(count: int, type_mix: dict[str, int]) -> dict[str, int]:
    """
    Turns an overall per-call question count into exact per-type sub-counts
    that always sum to `count`, proportional to the requested type_mix
    ratio (largest-remainder rounding). This exists because leaving the
    ratio to the model as a prompt hint measured badly wrong in practice —
    an 80/10/10 request came back 91/7/2 over a 100-question batch, with
    several chunks ignoring the non-MC types entirely. Locking each
    sub-call's grammar to a single type (see _build_response_schema) makes
    the ratio a structural guarantee instead of a suggestion.
    """

    relevant = {
        key: ratio
        for key, ratio in type_mix.items()
        if key in TYPE_LABELS and ratio > 0
    }

    if not relevant:
        # No usable type_mix at all — fall back to the schema's own
        # historical default (every question is Multiple Choice).
        return {"multiple_choice": count}

    total_ratio = sum(relevant.values())

    raw_shares = {
        key: (ratio / total_ratio) * count
        for key, ratio in relevant.items()
    }

    floors = {key: int(share) for key, share in raw_shares.items()}

    remainder = count - sum(floors.values())

    # Deterministic tie-break via sorted(), not dict iteration order.
    remainder_order = sorted(
        relevant.keys(),
        key=lambda key: (raw_shares[key] - floors[key], key),
        reverse=True,
    )

    for key in remainder_order[:remainder]:
        floors[key] += 1

    return {key: value for key, value in floors.items() if value > 0}


def _build_user_prompt(request: GenerateRequest, count_this_call: int, type_label: str) -> str:
    parts = []

    if request.topic:
        parts.append(f"Topic: {request.topic}")

    if request.source_text:
        # Keep the source bounded so a single chunk call stays within a
        # small local model's context window.
        trimmed = request.source_text[:12000]
        parts.append(f"Reference source material:\n\"\"\"\n{trimmed}\n\"\"\"")

    parts.append(f"Write exactly {count_this_call} {type_label} question(s).")

    if request.points_per_question:
        parts.append(
            f"Every question must have \"points\": {request.points_per_question}."
        )

    if request.recent_questions:
        joined = "; ".join(request.recent_questions[-30:])
        parts.append(
            "Avoid repeating these already-used questions, and don't write "
            f"near-duplicates of them either: {joined}"
        )

    return "\n\n".join(parts)


def _parse_questions_payload(raw_text: str) -> list:
    """
    Ollama's json format mode guarantees *valid* JSON, not any particular
    shape. Handle every shape a small instruct model plausibly returns:
    a bare array, a {"questions": [...]} wrapper, or (when it ignores the
    "write N questions" instruction) a single question object on its own.
    """

    try:
        payload = json.loads(raw_text)
    except json.JSONDecodeError as error:
        raise GenerationFailedError(
            f"Model response was not valid JSON: {error}"
        ) from error

    if isinstance(payload, list):
        return payload

    if isinstance(payload, dict):
        for key in ("questions", "items", "data"):
            if isinstance(payload.get(key), list):
                return payload[key]

        if "question_text" in payload:
            return [payload]

    raise GenerationFailedError(
        "Model response did not contain a recognizable list of questions."
    )


# Generic English filler *and* this domain's own recurring question-stem
# words ("which", "following", "computing", "term", "refer"...) — stripping
# both is what makes the overlap coefficient actually discriminate between
# questions instead of just measuring how much boilerplate they share.
_CONTENT_STOPWORDS = frozenset({
    "a", "an", "the", "of", "in", "on", "is", "are", "was", "were", "be",
    "been", "being", "do", "does", "did", "to", "for", "and", "or", "as",
    "with", "that", "this", "these", "those", "it", "its", "by", "from",
    "at", "not", "no", "which", "what", "who", "whom", "how", "why",
    "when", "where", "following", "computing", "computer", "computers",
    "term", "refer", "refers", "referred", "commonly", "primary", "used",
    "example", "type", "types", "kind", "describe", "describes", "best",
})

_WORD_PATTERN = re.compile(r"[a-z0-9']+")


def _content_words(text: str) -> set[str]:
    words = _WORD_PATTERN.findall(text.lower())
    return {word for word in words if len(word) > 1 and word not in _CONTENT_STOPWORDS}


def _is_too_similar(candidate_text: str, seen_texts: list[str]) -> bool:
    """
    Overlap coefficient on content words, not character-level string
    similarity — see DUPLICATE_SIMILARITY_THRESHOLD for why. `seen_texts`
    holds already-normalized strings; this stays a plain function (not
    something needing pre-computed word sets) since a batch this size
    calls it at most a few hundred times, nowhere near where the repeated
    tokenizing would matter.
    """

    candidate_words = _content_words(candidate_text)

    if not candidate_words:
        return False

    best_ratio = 0.0
    best_seen = None

    for seen in seen_texts:

        seen_words = _content_words(seen)

        if not seen_words:
            continue

        overlap = len(candidate_words & seen_words)
        smaller_set_size = min(len(candidate_words), len(seen_words))

        ratio = overlap / smaller_set_size

        if ratio > best_ratio:
            best_ratio = ratio
            best_seen = seen

    if os.environ.get("QUESTION_BUILDER_DEBUG_DEDUPE"):
        verdict = "REJECT" if best_ratio >= DUPLICATE_SIMILARITY_THRESHOLD else "keep"
        print(f"[dedupe {verdict} ratio={best_ratio:.3f}] cand={candidate_text!r} vs best={best_seen!r}", flush=True)

    return best_ratio >= DUPLICATE_SIMILARITY_THRESHOLD


def _normalize_for_dedupe(text: str) -> str:
    return " ".join(text.strip().lower().split())


def _is_structurally_valid(question: GeneratedQuestion) -> bool:
    """
    Defense-in-depth on top of the schema (see _build_response_schema) —
    catches the rare case where a future model/runtime doesn't fully honor
    the grammar, the same way _parse_questions_payload does for shape.
    """

    if question.question_type == "Short Answer":
        # Without an answer key it couldn't be auto-graded.
        return any(answer.strip() for answer in question.accepted_answers)

    if question.question_type != "Multiple Choice":
        return True

    if len(question.choices) != 4:
        return False

    correct_count = sum(1 for choice in question.choices if choice.is_correct)

    return correct_count == 1


def _estimate_tokens(text: str) -> int:
    """
    Rough ~4 chars/token heuristic for English text. Good enough for sizing
    num_ctx conservatively — not meant for exact token accounting.
    """

    return max(1, len(text) // 4)


def _compute_num_ctx(request: GenerateRequest, response_budget: int) -> int:
    """
    Sized for the *whole chunked session*, not just this one call — and
    that distinction matters. Tested against a real Ollama server: once a
    model is loaded into a slot at a given context size, later requests in
    the same warm session (same keep_alive window) do NOT resize it; the
    size from the first call sticks. A batch's chunk calls all reuse the
    same source_text but grow `recent_questions` over time, so sizing from
    only the *current* call's message would silently risk truncating a
    later chunk once recent_questions grows past what the first call
    reserved. Size for the worst case this session will reach instead.
    """

    system_tokens = _estimate_tokens(SYSTEM_PROMPT)

    topic_tokens = _estimate_tokens(request.topic or "")

    # source_text is fixed for the whole batch (set once at batch creation,
    # reused unchanged on every chunk call) — safe to size from its actual
    # length rather than a worst case.
    source_tokens = _estimate_tokens((request.source_text or "")[:12000])

    # recent_questions grows across chunk calls up to this cap (see
    # _build_user_prompt) — size for the cap, not whatever's present now.
    recent_questions_tokens = 30 * 40  # ~40 tokens/title is a generous estimate

    prompt_tokens = (
        system_tokens + topic_tokens + source_tokens + recent_questions_tokens
    )

    # Padded for chat-template/grammar overhead the char heuristic doesn't
    # capture — better to over-allocate a little than truncate.
    needed = prompt_tokens + response_budget + 512

    # Round up to the nearest 1024 for clean KV-cache allocation; clamped to
    # a range sane for a 4GB GPU (2048 floor covers the smallest requests
    # without paying for context they'll never use).
    return max(2048, min(8192, ((needed + 1023) // 1024) * 1024))


def _call_model(
    model_name: str,
    messages: list[dict],
    response_schema: dict,
    count: int,
    request: GenerateRequest,
    temperature: float = 0.7,
) -> str:
    # Imported here so the service's tests run without the Ollama client.
    import ollama

    num_predict = count * config.TOKENS_PER_QUESTION + 256

    response = ollama.chat(
        model=model_name,
        messages=messages,
        # Full JSON schema (not just "json") — Ollama constrains decoding to
        # the grammar, so the shape and the array length are guaranteed.
        format=response_schema,
        keep_alive=config.KEEP_ALIVE,
        options={
            "temperature": temperature,
            # Bounded so a rambling response can't run away; the grammar
            # normally stops well before this.
            "num_predict": num_predict,
            "num_ctx": _compute_num_ctx(request, num_predict),
        },
    )

    return response["message"]["content"]


def _generate_type_batch(
    model_name: str,
    type_label: str,
    subcount: int,
    request: GenerateRequest,
    seen_texts: list[str],
) -> list[GeneratedQuestion]:
    """
    Generates exactly `subcount` questions of a single type, dropping any
    that duplicate something already seen (this call or an earlier chunk,
    across every type) or that fail the structural check, then retries a
    bounded number of times to backfill whatever was dropped. `seen_texts`
    is mutated in place so later types in the same /generate call — and
    later retries of this one — see everything accepted so far.
    """

    accepted: list[GeneratedQuestion] = []

    remaining = subcount

    for attempt in range(MAX_DEDUPE_RETRIES + 1):

        if remaining <= 0:
            break

        response_schema = _build_response_schema(remaining, type_label)

        messages = [
            {"role": "system", "content": SYSTEM_PROMPT},
            {
                "role": "user",
                "content": _build_user_prompt(request, remaining, type_label),
            },
        ]

        # Escalate temperature on retries. Measured directly (see the
        # dedupe debug log): re-asking this model the same prompt at the
        # same temperature after a rejection mostly reproduces its own
        # previous answer almost verbatim — the "avoid these" instruction
        # alone doesn't reliably break it out of its highest-probability
        # completion. Sampling more randomly on retry does.
        temperature = 0.7 if attempt == 0 else min(0.7 + attempt * 0.2, 1.1)

        raw = _call_model(
            model_name, messages, response_schema, remaining, request, temperature=temperature
        )

        payload = _parse_questions_payload(raw)

        candidates = [GeneratedQuestion.model_validate(item) for item in payload]

        for candidate in candidates:

            if len(accepted) >= subcount:
                break

            if not _is_structurally_valid(candidate):
                continue

            normalized = _normalize_for_dedupe(candidate.question_text)

            if _is_too_similar(normalized, seen_texts):
                continue

            accepted.append(candidate)
            seen_texts.append(normalized)

        remaining = subcount - len(accepted)

        if remaining > 0:

            # Feed the model its own rejected attempts as "already used" too
            # — otherwise a retry just regenerates the same duplicate.
            request = request.model_copy(update={
                "recent_questions": (
                    list(request.recent_questions or [])
                    + [q.question_text[:80] for q in accepted]
                )[-30:]
            })

    return accepted


def generate_questions(
    request: GenerateRequest,
) -> tuple[list[GeneratedQuestion], str]:
    """
    Generate up to config.MAX_QUESTIONS_PER_CALL questions in one call,
    split into one schema-locked sub-call per requested type (see
    _split_count_by_type_mix) so the type ratio, per-MC-question choice
    count, and exactly-one-correct-answer are all structural guarantees
    rather than prompted hints. Raises GenerationBusyError if another job
    is already running (single shared GPU — no concurrent generation).
    """

    if not _generation_lock.acquire(blocking=False):
        raise GenerationBusyError(
            "Another generation job is already running on this machine."
        )

    try:
        count_this_call = min(request.count, config.MAX_QUESTIONS_PER_CALL)

        type_counts = _split_count_by_type_mix(count_this_call, request.type_mix or {})

        model_name = config.MODEL_NAME

        # Grows across every sub-call below (and is mutated in place inside
        # _generate_type_batch), so a question generated for one type in
        # this call can't duplicate one just generated for another type in
        # the same call — on top of the cross-chunk history the caller
        # passes in via request.recent_questions.
        seen_texts = [
            _normalize_for_dedupe(text) for text in (request.recent_questions or [])
        ]

        all_questions: list[GeneratedQuestion] = []

        for type_key, subcount in type_counts.items():

            type_label = TYPE_LABELS[type_key]

            all_questions.extend(
                _generate_type_batch(model_name, type_label, subcount, request, seen_texts)
            )

        for question in all_questions:

            if question.question_type == "Multiple Choice":

                question.math_check = verification.check_multiple_choice_math(
                    question.question_text,
                    [choice.model_dump() for choice in question.choices],
                )

        return all_questions, model_name

    finally:
        _generation_lock.release()
