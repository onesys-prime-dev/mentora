"""A small pool of models behind one provider. A call goes to the first model
the governor allows; when a model is rate limited, missing or erroring, the
next one is tried. A short wait is slept; a long one moves on."""

import time
from dataclasses import dataclass
from typing import Callable

from .errors import (
    FreeQuotaExhausted,
    ProviderAuth,
    ProviderError,
    ProviderModelMissing,
    ProviderRateLimited,
    QbError,
)
from .governor import RateGovernor
from .providers.base import Completion, Provider

# Reasoning models spend hidden tokens; reserve some beyond the visible answer.
REASONING_ALLOWANCE = 500


@dataclass
class CallResult:
    completion: Completion
    waited_seconds: float
    models_tried: list[str]


class ModelPool:
    def __init__(
        self,
        provider: Provider,
        models: list[str],
        governor: RateGovernor,
        *,
        max_wait: float = 90.0,
        clock: Callable[[], float] = time.time,
        sleep: Callable[[float], None] = time.sleep,
    ):
        self.provider = provider
        self.models = models
        self.governor = governor
        self.max_wait = max_wait
        self.clock = clock
        self.sleep = sleep

    @staticmethod
    def estimate_tokens(system: str, user: str, max_output_tokens: int) -> int:
        return (len(system) + len(user)) // 4 + max_output_tokens + REASONING_ALLOWANCE

    def complete(
        self,
        system: str,
        user: str,
        *,
        json_mode: bool = True,
        max_output_tokens: int = 1024,
        on_wait: Callable[[float], None] | None = None,
    ) -> CallResult:
        needed = self.estimate_tokens(system, user, max_output_tokens)
        tried: list[str] = []
        waited = 0.0
        shortest_wait: float | None = None
        last_error: QbError | None = None
        any_available = False

        for model in self.models:
            wait = self.governor.wait_seconds(model, needed, self.clock())

            if wait is None:
                continue  # no allowance left on this model today

            any_available = True

            if wait > self.max_wait:
                shortest_wait = wait if shortest_wait is None else min(shortest_wait, wait)
                continue

            if wait > 0:
                if on_wait:
                    on_wait(wait)

                self.sleep(wait)
                waited += wait

            tried.append(model)

            try:
                completion = self.provider.complete(
                    model, system, user, json_mode=json_mode, max_output_tokens=max_output_tokens
                )
            except ProviderRateLimited as exc:
                self.governor.penalise(model, exc.retry_after, self.clock())
                last_error = exc
                continue
            except (ProviderModelMissing, ProviderError) as exc:
                last_error = exc
                continue

            self.governor.record(
                model, completion.tokens_in + completion.tokens_out, self.clock(), completion.headers
            )

            return CallResult(completion, waited, tried)

        if isinstance(last_error, ProviderAuth):
            raise last_error

        if not any_available:
            raise FreeQuotaExhausted("Today's free allowance is used up.")

        if shortest_wait is not None and last_error is None:
            raise ProviderRateLimited("The free allowance is busy.", retry_after=shortest_wait)

        raise last_error or ProviderError("No model could answer.")
