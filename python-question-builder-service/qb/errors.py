"""Typed failures. Each carries the stable `code` PHP stores on a failed job
(handout Q3), so a provider's own errors never leak past the provider module."""


class QbError(Exception):
    code = "provider_error"
    retryable = False

    def __init__(self, message: str = "", *, retry_after: float | None = None):
        super().__init__(message or self.code)
        self.message = message or self.code
        self.retry_after = retry_after


class ProviderRateLimited(QbError):
    code = "provider_rate_limited"
    retryable = True


class ProviderAuth(QbError):
    code = "provider_auth"


class ProviderModelMissing(QbError):
    code = "provider_model_missing"


class ProviderError(QbError):
    code = "provider_error"
    retryable = True


class ProviderRefused(QbError):
    code = "provider_refused"


class FreeQuotaExhausted(QbError):
    code = "free_quota"


class InvalidRequest(QbError):
    code = "invalid_request"


class SourceUnreadable(QbError):
    code = "source_unreadable"


class JobCancelled(Exception):
    """The job was cancelled in PHP; stop without reporting a failure."""
