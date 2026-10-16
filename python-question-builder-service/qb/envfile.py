"""A tiny `.env` reader for running the worker by hand on a developer machine.

The deployed worker gets its settings from the service unit's environment, not
from a file in the project. Locally there is no unit, so `python -m qb.worker`
reads `python-question-builder-service/.env` (git-ignored) first. A variable
that is already set in the real environment always wins, so a file can never
override what the process was started with.
"""

import os
from pathlib import Path

DEFAULT_PATH = Path(__file__).resolve().parent.parent / ".env"


def parse(text: str) -> dict[str, str]:
    """`KEY=VALUE` lines. Blank lines and `#` comments are skipped, an optional
    leading `export ` is allowed, and a value may be wrapped in matching quotes."""

    values: dict[str, str] = {}

    for raw in text.splitlines():
        line = raw.strip()

        if not line or line.startswith("#"):
            continue

        if line.startswith("export "):
            line = line[len("export "):].lstrip()

        key, separator, value = line.partition("=")
        key = key.strip()

        if not separator or not key:
            continue

        value = value.strip()

        if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
            value = value[1:-1]
        elif " #" in value:
            value = value.split(" #", 1)[0].rstrip()

        values[key] = value

    return values


def load(path: Path | str | None = None, environ: dict[str, str] | None = None) -> list[str]:
    """Reads the file into `environ` (the real environment by default) without
    replacing anything already set. Returns the names that were set, never
    their values. A missing file is not an error."""

    target = Path(path) if path else Path(os.environ.get("QB_ENV_FILE", DEFAULT_PATH))
    env = os.environ if environ is None else environ

    try:
        text = target.read_text(encoding="utf-8")
    except OSError:
        return []

    applied = []

    for key, value in parse(text).items():
        if key not in env:
            env[key] = value
            applied.append(key)

    return applied
