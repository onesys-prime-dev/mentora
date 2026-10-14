"""Rate governor: decides when a call may go so the free limits are never
exceeded, per model. Pure: the clock is always passed in.

It tracks what we spent in sliding one-minute and one-day windows, folds in
what the provider reports (remaining tokens and when they reset, or a
retry-after after a 429), and answers one question: how many seconds must this
call wait, or can it never fit?"""

import re
from collections import deque
from dataclasses import dataclass, field

from .providers.base import Capabilities

MINUTE = 60.0
DAY = 86_400.0

_DURATION = re.compile(r"(?:(\d+(?:\.\d+)?)h)?(?:(\d+(?:\.\d+)?)m(?!s))?(?:(\d+(?:\.\d+)?)s)?(?:(\d+(?:\.\d+)?)ms)?")


def parse_duration(text: str | None) -> float | None:
    """Groq reset headers look like "7.66s", "2m59.56s" or "120ms"."""

    if not text:
        return None

    match = _DURATION.fullmatch(text.strip())

    if not match or not any(match.groups()):
        try:
            return float(text)
        except ValueError:
            return None

    hours, minutes, seconds, millis = (float(g) if g else 0.0 for g in match.groups())

    return hours * 3600 + minutes * 60 + seconds + millis / 1000


@dataclass
class _ModelState:
    caps: Capabilities
    spent: deque = field(default_factory=deque)  # (timestamp, tokens)
    blocked_until: float = 0.0
    hint_remaining: int | None = None  # tokens left this minute, as reported
    hint_reset_at: float = 0.0


class RateGovernor:
    def __init__(self, limits: dict[str, Capabilities]):
        self._state = {model: _ModelState(caps) for model, caps in limits.items()}

    def models(self) -> list[str]:
        return list(self._state)

    def wait_seconds(self, model: str, tokens: int, now: float) -> float | None:
        """Seconds to wait before a call of about `tokens` may start, or None
        when it cannot run at all today (daily allowance gone, or larger than
        a whole minute's limit)."""

        state = self._state[model]
        caps = state.caps
        self._prune(state, now)

        if caps.tokens_per_minute is not None and tokens > caps.tokens_per_minute:
            return None

        if caps.tokens_per_day is not None:
            if sum(t for _, t in state.spent) + tokens > caps.tokens_per_day:
                return None

        if caps.requests_per_day is not None and len(state.spent) + 1 > caps.requests_per_day:
            return None

        wait = max(0.0, state.blocked_until - now)

        recent = [(ts, t) for ts, t in state.spent if ts > now - MINUTE]

        if caps.tokens_per_minute is not None:
            used = sum(t for _, t in recent)
            freed = 0
            index = 0

            while used + tokens - freed > caps.tokens_per_minute and index < len(recent):
                freed += recent[index][1]
                wait = max(wait, recent[index][0] + MINUTE - now)
                index += 1

        if caps.requests_per_minute is not None and len(recent) + 1 > caps.requests_per_minute:
            overflow = len(recent) + 1 - caps.requests_per_minute
            wait = max(wait, recent[overflow - 1][0] + MINUTE - now)

        if state.hint_remaining is not None and state.hint_remaining < tokens and state.hint_reset_at > now:
            wait = max(wait, state.hint_reset_at - now)

        return wait

    def record(self, model: str, tokens: int, now: float, headers: dict[str, str] | None = None) -> None:
        state = self._state[model]
        state.spent.append((now, max(0, tokens)))

        headers = headers or {}
        remaining = headers.get("x-ratelimit-remaining-tokens")
        reset = parse_duration(headers.get("x-ratelimit-reset-tokens"))

        if remaining is not None and remaining.isdigit():
            state.hint_remaining = int(remaining)
            state.hint_reset_at = now + (reset or 0.0)

    def penalise(self, model: str, retry_after: float | None, now: float) -> None:
        """A 429: nothing on this model until retry-after has passed."""

        self._state[model].blocked_until = now + (retry_after if retry_after is not None else MINUTE)

    def used_today(self, model: str, now: float) -> int:
        state = self._state[model]
        self._prune(state, now)

        return sum(t for _, t in state.spent)

    @staticmethod
    def _prune(state: _ModelState, now: float) -> None:
        while state.spent and state.spent[0][0] <= now - DAY:
            state.spent.popleft()
