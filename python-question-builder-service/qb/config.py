"""Worker settings, read from the environment, and the one place the worker is
assembled: provider, model pool, governor, handlers and the PHP client.

Secrets (the provider key and the shared secret) come only from the
environment of the service unit, never from a file in the repository."""

import os
import socket
from dataclasses import dataclass

from .errors import InvalidRequest
from .governor import RateGovernor
from .php_client import PhpClient, Post, make_post
from .pipeline import make_generate_handler
from .pool import ModelPool
from .providers.base import Provider
from .providers.fake import FakeProvider
from .providers.openai_compat import GROQ_BASE_URL, GROQ_FREE_LIMITS, OpenAICompatProvider, Transport, urllib_transport
from .worker import Worker

DEFAULT_MODELS = ["openai/gpt-oss-120b", "qwen/qwen3.8-27b", "openai/gpt-oss-20b"]


class ConfigError(Exception):
    pass


@dataclass(frozen=True)
class Settings:
    php_url: str
    worker_secret: str
    worker_id: str
    backend: str
    api_key: str
    base_url: str
    models: list[str]
    # False only for a staging site with a self-signed certificate.
    php_verify_tls: bool = True

    @property
    def key_configured(self) -> bool:
        return bool(self.api_key)


def from_env(env: dict[str, str] | None = None) -> Settings:
    env = dict(os.environ) if env is None else env

    secret = env.get("QB_WORKER_SECRET", "").strip()

    if not secret:
        raise ConfigError("QB_WORKER_SECRET is not set; the worker cannot sign in to Mentora.")

    backend = env.get("QB_BACKEND", "groq").strip().lower()

    if backend not in ("groq", "fake"):
        raise ConfigError(f"QB_BACKEND must be groq or fake, not {backend!r}.")

    models = [m.strip() for m in env.get("QB_MODELS", "").split(",") if m.strip()] or list(DEFAULT_MODELS)

    return Settings(
        php_url=env.get("QB_PHP_URL", "http://127.0.0.1").strip(),
        worker_secret=secret,
        worker_id=env.get("QB_WORKER_ID", "").strip() or f"{socket.gethostname()}-{os.getpid()}",
        backend=backend,
        api_key=env.get("GROQ_API_KEY", "").strip(),
        base_url=env.get("QB_BASE_URL", GROQ_BASE_URL).strip(),
        models=models,
        php_verify_tls=env.get("QB_PHP_VERIFY_TLS", "true").strip().lower() not in ("false", "0", "no", "off"),
    )


def build_provider(settings: Settings, transport: Transport = urllib_transport) -> Provider:
    if settings.backend == "fake":
        return FakeProvider()

    return OpenAICompatProvider(settings.api_key, base_url=settings.base_url, transport=transport)


def build_worker(
    settings: Settings,
    *,
    post: Post | None = None,
    transport: Transport = urllib_transport,
    provider: Provider | None = None,
) -> Worker:
    provider = provider or build_provider(settings, transport)
    governor = RateGovernor({model: GROQ_FREE_LIMITS for model in settings.models})
    pool = ModelPool(provider, settings.models, governor)
    php = PhpClient(
        settings.php_url,
        settings.worker_secret,
        settings.worker_id,
        post=post or make_post(settings.php_verify_tls),
    )

    return Worker(php, {"generate": with_material(make_generate_handler(pool))}, backend=settings.backend)


def with_material(handler):
    """Refuses a job that carries nothing to read, with a clear failure code,
    instead of letting the pipeline fall over on it."""

    def checked(job: dict, ctx) -> dict:
        payload = job.get("payload") or {}
        has_items = any(isinstance(i, dict) and str(i.get("text") or "").strip() for i in payload.get("items") or [])
        has_text = bool(str(payload.get("source_text") or "").strip())
        has_topic = bool(str(payload.get("topic") or "").strip())

        if not (has_items or has_text or has_topic):
            raise InvalidRequest("This batch has no material to read.")

        return handler(job, ctx)

    return checked
