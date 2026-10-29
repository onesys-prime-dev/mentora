import json

import pytest

from qb.errors import (
    FreeQuotaExhausted,
    InvalidRequest,
    JobCancelled,
    ProviderAuth,
    ProviderError,
    ProviderModelMissing,
    ProviderRateLimited,
)
from qb.php_client import PhpClient, PhpUnavailable
from qb.providers.openai_compat import OpenAICompatProvider
from qb.worker import RESTART_EXIT_CODE, StopRequest, Worker


def reply(status=200, headers=None, body=None):
    raw = json.dumps(body if body is not None else {}).encode()

    return lambda url, h, b, t: (status, headers or {}, raw)


GOOD = {
    "choices": [{"message": {"content": '{"questions": []}'}, "finish_reason": "stop"}],
    "usage": {"prompt_tokens": 120, "completion_tokens": 30},
}


def test_a_good_reply_carries_text_usage_and_rate_headers():
    provider = OpenAICompatProvider(
        "key", transport=reply(headers={"x-ratelimit-remaining-tokens": "7000", "content-type": "x"}, body=GOOD)
    )

    out = provider.complete("m", "sys", "user")

    assert out.text == '{"questions": []}'
    assert (out.tokens_in, out.tokens_out) == (120, 30)
    assert out.headers == {"x-ratelimit-remaining-tokens": "7000"}


def test_the_request_has_the_key_the_model_and_json_mode_but_no_key_in_the_body():
    seen = {}

    def transport(url, headers, body, timeout):
        seen.update(url=url, headers=headers, body=json.loads(body))

        return 200, {}, json.dumps(GOOD).encode()

    OpenAICompatProvider("sk-test", transport=transport).complete("openai/gpt-oss-120b", "s", "u", max_output_tokens=300)

    assert seen["url"].endswith("/chat/completions")
    assert seen["headers"]["Authorization"] == "Bearer sk-test"
    assert seen["body"]["model"] == "openai/gpt-oss-120b"
    assert seen["body"]["response_format"] == {"type": "json_object"}
    assert seen["body"]["max_completion_tokens"] == 300
    assert "sk-test" not in json.dumps(seen["body"])


@pytest.mark.parametrize(
    "status,expected",
    [(429, ProviderRateLimited), (401, ProviderAuth), (403, ProviderAuth), (404, ProviderModelMissing),
     (400, InvalidRequest), (500, ProviderError), (503, ProviderError)],
)
def test_each_http_status_maps_to_a_typed_error(status, expected):
    provider = OpenAICompatProvider("key", transport=reply(status=status, headers={"retry-after": "7"}))

    with pytest.raises(expected) as info:
        provider.complete("m", "s", "u")

    if status == 429:
        assert info.value.retry_after == 7.0


def test_a_network_failure_and_garbage_are_provider_errors():
    def down(url, h, b, t):
        raise TimeoutError()

    with pytest.raises(ProviderError):
        OpenAICompatProvider("key", transport=down).complete("m", "s", "u")

    with pytest.raises(ProviderError):
        OpenAICompatProvider("key", transport=lambda *a: (200, {}, b"not json")).complete("m", "s", "u")


def test_no_key_is_an_auth_error_before_any_call():
    with pytest.raises(ProviderAuth):
        OpenAICompatProvider("").complete("m", "s", "u")


# ---------------------------------------------------------------- the worker


class FakePhp:
    """Stands in for PhpClient: a queue of jobs and a record of what was reported."""

    def __init__(self, jobs=None, down=False, progress_state="running"):
        self.jobs = list(jobs or [])
        self.down = down
        self.progress_state = progress_state
        self.events: list[tuple] = []

    def claim(self, version="", backend=""):
        if self.down:
            raise PhpUnavailable("down")

        return self.jobs.pop(0) if self.jobs else None

    def progress(self, job_id, **fields):
        self.events.append(("progress", job_id, fields))

        return self.progress_state

    def complete(self, job_id, result):
        if self.down:
            raise PhpUnavailable("down")

        self.events.append(("complete", job_id, result))

        return True

    def fail(self, job_id, code, message=""):
        self.events.append(("fail", job_id, code))

        return True


def job(kind="generate", id=7):
    return {"id": id, "kind": kind, "payload": {}}


def test_an_empty_queue_is_idle():
    assert Worker(FakePhp(), {}).run_once() == "idle"


def test_a_job_runs_reports_progress_and_completes():
    def handler(j, ctx):
        ctx.progress(stage="write", done=1, of=2)

        return {"questions": []}

    php = FakePhp([job()])

    assert Worker(php, {"generate": handler}).run_once() == "done"
    assert php.events[-1] == ("complete", 7, {"questions": []})


def test_an_unknown_kind_fails_with_a_code():
    php = FakePhp([job("translate")])

    assert Worker(php, {}).run_once() == "failed"
    assert php.events[-1] == ("fail", 7, "invalid_request")


@pytest.mark.parametrize(
    "error,code",
    [(ProviderRateLimited("x"), "provider_rate_limited"), (FreeQuotaExhausted("x"), "free_quota"), (ProviderAuth("x"), "provider_auth")],
)
def test_a_typed_error_becomes_the_failure_code(error, code):
    def handler(j, ctx):
        raise error

    php = FakePhp([job()])

    assert Worker(php, {"generate": handler}).run_once() == "failed"
    assert php.events[-1] == ("fail", 7, code)


def test_an_unexpected_crash_is_reported_not_raised():
    def handler(j, ctx):
        raise RuntimeError("boom")

    php = FakePhp([job()])

    assert Worker(php, {"generate": handler}).run_once() == "failed"
    assert php.events[-1][2] == "provider_error"


def test_a_cancelled_job_stops_without_reporting_a_failure():
    def handler(j, ctx):
        ctx.progress(stage="write", done=0, of=1)

        return {"questions": []}

    php = FakePhp([job()], progress_state="cancelled")

    assert Worker(php, {"generate": handler}).run_once() == "cancelled"
    assert not any(e[0] in ("complete", "fail") for e in php.events)


def test_the_worker_survives_php_being_down_and_backs_off():
    php = FakePhp(down=True)
    sleeps: list[float] = []
    worker = Worker(php, {}, busy_seconds=3.0, sleep=sleeps.append)
    runs = iter(range(4))

    worker.run_forever(should_stop=lambda: next(runs, None) is None)

    assert sleeps == [6.0, 12.0, 24.0, 48.0]


def test_php_client_sends_the_secret_and_worker_id_and_refuses_bad_answers():
    sent = {}

    def post(url, headers, body, timeout):
        sent.update(url=url, headers=headers, body=json.loads(body))

        return 200, json.dumps({"job": None}).encode()

    client = PhpClient("http://127.0.0.1/", "s3cret", "w1", post=post)

    assert client.claim("1", "groq") is None
    assert sent["url"] == "http://127.0.0.1/internal/ai/claim"
    assert sent["headers"]["X-Worker-Secret"] == "s3cret"
    assert sent["body"]["worker_id"] == "w1"

    with pytest.raises(PhpUnavailable):
        PhpClient("http://x", "s", "w", post=lambda *a: (503, b"")).claim()

    with pytest.raises(PhpUnavailable):
        PhpClient("http://x", "s", "w", post=lambda *a: (_ for _ in ()).throw(OSError())).claim()


def test_every_provider_call_sends_a_real_user_agent():
    seen = {}

    def transport(url, headers, body, timeout):
        seen.update(headers)

        return 200, {}, json.dumps(GOOD).encode()

    OpenAICompatProvider("k", transport=transport).complete("m", "s", "u")

    assert seen["User-Agent"].startswith("mentora-question-builder/")
    assert "urllib" not in seen["User-Agent"].lower()


def test_a_refusal_that_is_not_json_still_says_what_the_gateway_said():
    provider = OpenAICompatProvider("k", transport=lambda *a: (403, {}, b"error code: 1010"))

    with pytest.raises(ProviderAuth) as info:
        provider.complete("m", "s", "u")

    assert "1010" in info.value.message


def test_a_failed_job_logs_its_code_and_reason(caplog):
    def handler(j, ctx):
        raise ProviderAuth("The provider refused the key.")

    php = FakePhp([job()])

    with caplog.at_level("WARNING", logger="qb.worker"):
        Worker(php, {"generate": handler}).run_once()

    assert "provider_auth" in caplog.text and "refused the key" in caplog.text


def test_reasoning_models_get_a_low_effort_and_others_are_left_alone():
    sent = []

    def transport(url, headers, body, timeout):
        sent.append(json.loads(body))

        return 200, {}, json.dumps(GOOD).encode()

    provider = OpenAICompatProvider("k", transport=transport)
    provider.complete("openai/gpt-oss-120b", "s", "u")
    provider.complete("qwen/qwen3.8-27b", "s", "u")

    assert sent[0]["reasoning_effort"] == "low"
    assert "reasoning_effort" not in sent[1]


def test_invalid_json_from_the_model_is_a_provider_error_so_another_model_can_try():
    body = json.dumps({"error": {"message": "Failed to validate JSON. Please adjust your prompt.", "code": "json_validate_failed"}})
    provider = OpenAICompatProvider("k", transport=lambda *a: (400, {}, body.encode()))

    with pytest.raises(ProviderError):
        provider.complete("m", "s", "u")

    other = json.dumps({"error": {"message": "max_tokens is too large"}})

    with pytest.raises(InvalidRequest):
        OpenAICompatProvider("k", transport=lambda *a: (400, {}, other.encode())).complete("m", "s", "u")


def test_a_stop_request_during_the_idle_wait_ends_the_loop_and_exits_clean():
    stop = StopRequest()
    worker = Worker(FakePhp(), {}, sleep=lambda seconds: stop._stop(None, None))

    worker.run_forever(should_stop=stop.event.is_set)

    assert stop.event.is_set()
    assert stop.exit_code == 0


def test_a_restart_request_exits_non_zero_so_the_unit_starts_it_again():
    stop = StopRequest()

    stop._restart(None, None)

    assert stop.event.is_set()
    assert stop.exit_code == RESTART_EXIT_CODE != 0
