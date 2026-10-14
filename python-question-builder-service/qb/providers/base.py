"""One interface every model provider implements, and a capabilities record the
pipeline reads instead of branching on a provider name."""

from dataclasses import dataclass, field
from typing import Protocol


@dataclass(frozen=True)
class Capabilities:
    """What a model can do and how much of it we may use."""

    structured_output: str = "json_mode"  # "schema" | "json_mode" | "none"
    image_input: bool = False
    prompt_caching: bool = False
    sampling_parameters: bool = True
    context_tokens: int = 131_072
    # Per-model rate limits; None means unknown or unlimited.
    requests_per_minute: int | None = None
    requests_per_day: int | None = None
    tokens_per_minute: int | None = None
    tokens_per_day: int | None = None


@dataclass
class Completion:
    text: str
    model: str
    tokens_in: int = 0
    tokens_out: int = 0
    # Lower-cased response headers worth keeping (x-ratelimit-*).
    headers: dict[str, str] = field(default_factory=dict)


class Provider(Protocol):
    name: str

    def capabilities(self, model: str) -> Capabilities: ...

    def complete(
        self,
        model: str,
        system: str,
        user: str,
        *,
        json_mode: bool = True,
        max_output_tokens: int = 1024,
    ) -> Completion: ...
