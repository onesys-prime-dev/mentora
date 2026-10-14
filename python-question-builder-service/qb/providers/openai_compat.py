"""Any OpenAI-compatible chat endpoint; Groq is the default. Standard library
only. The HTTP call is injectable so tests never touch a network, and every
failure is mapped onto a typed error here, so nothing above this module knows
a provider's status codes."""

import json
import urllib.error
import urllib.request
from typing import Callable

from ..errors import (
    InvalidRequest,
    ProviderAuth,
    ProviderError,
    ProviderModelMissing,
    ProviderRateLimited,
)
from .base import Capabilities, Completion

GROQ_BASE_URL = "https://api.groq.com/openai/v1"

# Published free-tier limits per model (the console table of 2026-10-14). The
# console is authoritative; these only seed the governor, which also reads the
# x-ratelimit-* headers on every response.
GROQ_FREE_LIMITS = Capabilities(
    structured_output="json_mode",
    requests_per_minute=30,
    requests_per_day=1000,
    tokens_per_minute=8000,
    tokens_per_day=200_000,
)

Transport = Callable[[str, dict, bytes, float], tuple[int, dict, bytes]]


def urllib_transport(url: str, headers: dict, body: bytes, timeout: float) -> tuple[int, dict, bytes]:
    request = urllib.request.Request(url, data=body, headers=headers, method="POST")

    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, {k.lower(): v for k, v in response.headers.items()}, response.read()
    except urllib.error.HTTPError as exc:
        return exc.code, {k.lower(): v for k, v in exc.headers.items()}, exc.read()


class OpenAICompatProvider:
    name = "openai_compat"

    def __init__(
        self,
        api_key: str,
        base_url: str = GROQ_BASE_URL,
        caps: Capabilities = GROQ_FREE_LIMITS,
        transport: Transport = urllib_transport,
        timeout: float = 60.0,
    ):
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")
        self.caps = caps
        self.transport = transport
        self.timeout = timeout

    def capabilities(self, model: str) -> Capabilities:
        return self.caps

    def complete(self, model, system, user, *, json_mode=True, max_output_tokens=1024) -> Completion:
        if not self.api_key:
            raise ProviderAuth("No API key is configured.")

        payload = {
            "model": model,
            "messages": [
                {"role": "system", "content": system},
                {"role": "user", "content": user},
            ],
            "max_completion_tokens": max_output_tokens,
        }

        if json_mode:
            payload["response_format"] = {"type": "json_object"}

        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json",
        }

        try:
            status, response_headers, raw = self.transport(
                f"{self.base_url}/chat/completions", headers, json.dumps(payload).encode(), self.timeout
            )
        except Exception as exc:  # network down, timeout, DNS
            raise ProviderError(f"The provider could not be reached: {type(exc).__name__}") from exc

        if status != 200:
            raise self._error(status, response_headers, raw)

        try:
            body = json.loads(raw)
            choice = body["choices"][0]
            text = choice["message"]["content"] or ""
            usage = body.get("usage") or {}
        except (ValueError, KeyError, IndexError, TypeError) as exc:
            raise ProviderError("The provider sent a reply that could not be read.") from exc

        if choice.get("finish_reason") == "length" and not text.strip():
            raise ProviderError("The answer was cut off before it began.")

        return Completion(
            text=text,
            model=model,
            tokens_in=int(usage.get("prompt_tokens", 0)),
            tokens_out=int(usage.get("completion_tokens", 0)),
            headers={k: v for k, v in response_headers.items() if k.startswith("x-ratelimit") or k == "retry-after"},
        )

    @staticmethod
    def _error(status: int, headers: dict, raw: bytes):
        detail = ""

        try:
            detail = str((json.loads(raw).get("error") or {}).get("message", ""))[:200]
        except Exception:
            pass

        if status == 429:
            try:
                retry_after = float(headers.get("retry-after", ""))
            except ValueError:
                retry_after = None

            return ProviderRateLimited(detail or "Rate limited.", retry_after=retry_after)

        if status in (401, 403):
            return ProviderAuth(detail or "The provider refused the key.")

        if status == 404:
            return ProviderModelMissing(detail or "The model is not available.")

        if status in (400, 413, 422):
            return InvalidRequest(detail or "The provider rejected the request.")

        return ProviderError(detail or f"The provider answered {status}.")
