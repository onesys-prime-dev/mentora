"""A scripted provider for tests: replies, or errors, in order."""

from typing import Callable

from ..errors import QbError
from .base import Capabilities, Completion


class FakeProvider:
    name = "fake"

    def __init__(self, script: list | None = None, caps: Capabilities | None = None):
        # Each item is a str (reply text), a Completion, a QbError (raised),
        # or a callable(model, system, user) returning one of those.
        self.script = list(script or [])
        self.caps = caps or Capabilities()
        self.calls: list[dict] = []

    def capabilities(self, model: str) -> Capabilities:
        return self.caps

    def complete(self, model, system, user, *, json_mode=True, max_output_tokens=1024) -> Completion:
        self.calls.append({"model": model, "system": system, "user": user, "json_mode": json_mode})

        item = self.script.pop(0) if self.script else "{}"

        if callable(item):
            item = item(model, system, user)

        if isinstance(item, QbError):
            raise item

        if isinstance(item, Completion):
            return item

        return Completion(text=str(item), model=model, tokens_in=len(user) // 4, tokens_out=len(str(item)) // 4)
