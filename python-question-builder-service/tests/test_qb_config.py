import json

import pytest

from qb import config
from qb.providers.openai_compat import GROQ_BASE_URL


def env(**extra):
    return {"QB_WORKER_SECRET": "shh", **extra}


def test_defaults_are_groq_with_the_three_model_pool():
    s = config.from_env(env(GROQ_API_KEY="gsk_x"))

    assert s.backend == "groq" and s.base_url == GROQ_BASE_URL
    assert s.models == ["openai/gpt-oss-120b", "qwen/qwen3.8-27b", "openai/gpt-oss-20b"]
    assert s.key_configured and s.php_url == "http://127.0.0.1"
    assert s.worker_id


def test_the_models_and_ids_can_be_set():
    s = config.from_env(env(QB_MODELS=" a/b , c/d ", QB_WORKER_ID="w9", QB_PHP_URL="http://localhost:8080"))

    assert s.models == ["a/b", "c/d"] and s.worker_id == "w9" and s.php_url == "http://localhost:8080"
    assert not s.key_configured


def test_a_missing_secret_or_unknown_backend_stops_the_start():
    with pytest.raises(config.ConfigError):
        config.from_env({"GROQ_API_KEY": "k"})

    with pytest.raises(config.ConfigError):
        config.from_env(env(QB_BACKEND="openai"))


def test_the_assembled_worker_has_the_generate_handler_and_reports_a_missing_key_as_a_code():
    posted = []
    jobs = [{"id": 5, "kind": "generate", "payload": {"source_text": "Routers forward packets.", "count": 1}}]

    def post(url, headers, body, timeout):
        path = url.rsplit("/", 1)[1]
        posted.append((path, json.loads(body)))

        if path == "claim":
            return 200, json.dumps({"job": jobs.pop(0) if jobs else None}).encode()

        return 200, json.dumps({"state": "running", "accepted": True}).encode()

    worker = config.build_worker(config.from_env(env()), post=post, transport=lambda *a: (_ for _ in ()).throw(AssertionError("no network")))

    # With no provider key, the first model call is refused before any network call.
    assert worker.run_once() == "failed"

    fails = [body for path, body in posted if path == "fail"]
    assert fails and fails[0]["code"] == "provider_auth"
    assert any(path == "progress" for path, _ in posted)


def test_certificate_checking_is_on_unless_explicitly_turned_off():
    assert config.from_env(env()).php_verify_tls is True
    assert config.from_env(env(QB_PHP_VERIFY_TLS="true")).php_verify_tls is True

    for off in ("false", "0", "No", "OFF"):
        assert config.from_env(env(QB_PHP_VERIFY_TLS=off)).php_verify_tls is False


def test_the_http_call_carries_an_unverified_context_only_when_asked(monkeypatch):
    import ssl
    import urllib.request

    seen = {}

    class Response:
        status = 200

        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

        def read(self):
            return b"{}"

    def fake_urlopen(request, timeout=None, context=None):
        seen["context"] = context

        return Response()

    monkeypatch.setattr(urllib.request, "urlopen", fake_urlopen)

    from qb.php_client import make_post

    make_post(True)("https://x/y", {}, b"{}", 5)
    assert seen["context"] is None

    make_post(False)("https://x/y", {}, b"{}", 5)
    assert isinstance(seen["context"], ssl.SSLContext) and seen["context"].verify_mode == ssl.CERT_NONE
