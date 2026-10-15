import pytest

from qb.errors import (
    FreeQuotaExhausted,
    ProviderAuth,
    ProviderError,
    ProviderModelMissing,
    ProviderRateLimited,
)
from qb.governor import RateGovernor, parse_duration
from qb.pool import ModelPool
from qb.providers.base import Capabilities, Completion
from qb.providers.fake import FakeProvider

FREE = Capabilities(requests_per_minute=30, requests_per_day=1000, tokens_per_minute=8000, tokens_per_day=200_000)


def test_parse_duration_understands_groq_reset_headers():
    assert parse_duration("7.66s") == pytest.approx(7.66)
    assert parse_duration("2m59.56s") == pytest.approx(179.56)
    assert parse_duration("120ms") == pytest.approx(0.12)
    assert parse_duration("12") == 12.0
    assert parse_duration("") is None and parse_duration("soon") is None


def test_a_call_that_fits_goes_at_once():
    gov = RateGovernor({"m": FREE})

    assert gov.wait_seconds("m", 3000, now=100.0) == 0.0


def test_the_per_minute_token_limit_is_never_exceeded():
    gov = RateGovernor({"m": FREE})
    gov.record("m", 6000, now=100.0)

    # 6000 + 3000 > 8000: wait until the first call leaves the minute window.
    assert gov.wait_seconds("m", 3000, now=110.0) == pytest.approx(50.0)
    assert gov.wait_seconds("m", 3000, now=160.0) == 0.0


def test_a_call_larger_than_a_whole_minute_can_never_run():
    assert RateGovernor({"m": FREE}).wait_seconds("m", 9000, now=0.0) is None


def test_the_daily_allowance_stops_a_model():
    gov = RateGovernor({"m": FREE})

    for i in range(25):
        gov.record("m", 7900, now=i * 61.0)

    assert gov.used_today("m", now=25 * 61.0) == 197_500
    assert gov.wait_seconds("m", 3000, now=25 * 61.0) is None
    # A day later the window has moved on.
    assert gov.wait_seconds("m", 3000, now=25 * 61.0 + 86_500) == 0.0


def test_the_request_per_minute_limit_is_respected():
    gov = RateGovernor({"m": Capabilities(requests_per_minute=2)})
    gov.record("m", 10, now=0.0)
    gov.record("m", 10, now=5.0)

    assert gov.wait_seconds("m", 10, now=10.0) == pytest.approx(50.0)


def test_a_429_blocks_the_model_for_retry_after():
    gov = RateGovernor({"m": FREE})
    gov.penalise("m", 20.0, now=100.0)

    assert gov.wait_seconds("m", 100, now=105.0) == pytest.approx(15.0)


def test_reported_remaining_tokens_make_us_wait_for_the_reset():
    gov = RateGovernor({"m": FREE})
    gov.record("m", 100, now=0.0, headers={"x-ratelimit-remaining-tokens": "500", "x-ratelimit-reset-tokens": "12s"})

    assert gov.wait_seconds("m", 2000, now=1.0) == pytest.approx(11.0)


class Clock:
    def __init__(self):
        self.t = 1000.0
        self.slept: list[float] = []

    def now(self):
        return self.t

    def sleep(self, seconds):
        self.slept.append(seconds)
        self.t += seconds


def pool(provider, models=("a", "b"), caps=FREE, max_wait=30.0):
    clock = Clock()
    gov = RateGovernor({m: caps for m in models})

    return ModelPool(provider, list(models), gov, max_wait=max_wait, clock=clock.now, sleep=clock.sleep), clock, gov


def test_the_first_model_answers_and_usage_is_recorded():
    provider = FakeProvider([Completion('{"ok":1}', "a", tokens_in=400, tokens_out=100)])
    p, _, gov = pool(provider)

    result = p.complete("sys", "user")

    assert result.completion.text == '{"ok":1}' and result.models_tried == ["a"]
    assert gov.used_today("a", 1000.0) == 500


def test_a_rate_limited_model_falls_back_to_the_next_and_is_penalised():
    provider = FakeProvider([ProviderRateLimited("slow", retry_after=40.0), '{"ok":2}'])
    p, clock, gov = pool(provider)

    result = p.complete("sys", "user")

    assert result.models_tried == ["a", "b"]
    assert provider.calls[1]["model"] == "b"
    assert gov.wait_seconds("a", 100, clock.now()) == pytest.approx(40.0)


@pytest.mark.parametrize("error", [ProviderModelMissing("gone"), ProviderError("500")])
def test_a_missing_or_failing_model_falls_back(error):
    p, _, _ = pool(FakeProvider([error, "{}"]))

    assert p.complete("s", "u").models_tried == ["a", "b"]


def test_a_refused_key_is_not_retried_on_other_models():
    p, _, _ = pool(FakeProvider([ProviderAuth("bad key"), ProviderAuth("bad key")]))

    with pytest.raises(ProviderAuth):
        p.complete("s", "u")


def test_a_short_wait_is_slept_and_reported():
    p, clock, gov = pool(FakeProvider(["{}"]), models=("a",), max_wait=90.0)
    gov.record("a", 7600, now=clock.now())
    waits: list[float] = []

    result = p.complete("s" * 100, "u" * 100, max_output_tokens=200, on_wait=waits.append)

    assert result.waited_seconds > 0 and clock.slept and waits


def test_a_wait_longer_than_the_limit_moves_on_then_asks_to_retry():
    p, clock, gov = pool(FakeProvider(["{}"]), models=("a",), max_wait=5.0)
    gov.record("a", 7900, now=clock.now())

    with pytest.raises(ProviderRateLimited) as info:
        p.complete("s", "u", max_output_tokens=200)

    assert info.value.retry_after and info.value.retry_after > 5.0


def test_every_model_out_of_allowance_is_a_free_quota_failure():
    tiny = Capabilities(tokens_per_minute=8000, tokens_per_day=10)
    p, _, _ = pool(FakeProvider(), caps=tiny)

    with pytest.raises(FreeQuotaExhausted) as info:
        p.complete("s", "u")

    assert info.value.code == "free_quota"


def test_the_provider_is_given_room_for_hidden_reasoning_on_top_of_the_answer():
    from qb.pool import REASONING_ALLOWANCE

    provider = FakeProvider(["{}"])
    p, _, _ = pool(provider)

    p.complete("s", "u", max_output_tokens=300)

    assert provider.calls[0]["max_output_tokens"] == 300 + REASONING_ALLOWANCE
