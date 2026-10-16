"""The worker loop: claim a job from PHP, run its handler, report. No port is
opened; everything is pulled from PHP.

    python -m qb.worker

A handler is `handler(job, ctx) -> result dict`; it reports progress through
`ctx.progress(...)`, which also learns of a cancellation and stops the job.
Stages are added as handlers by later branches; this skeleton only needs the
loop to be correct, to survive PHP going away, and to report typed failures."""

import logging
import os
import time
from dataclasses import dataclass
from typing import Callable

from .errors import JobCancelled, QbError
from .php_client import PhpClient, PhpUnavailable

log = logging.getLogger("qb.worker")

VERSION = "0.1"

Handler = Callable[[dict, "JobContext"], dict]


@dataclass
class JobContext:
    php: PhpClient
    job_id: int

    def progress(self, **fields) -> None:
        if self.php.progress(self.job_id, **fields) == "cancelled":
            raise JobCancelled()


class Worker:
    def __init__(
        self,
        php: PhpClient,
        handlers: dict[str, Handler],
        *,
        backend: str = "",
        idle_seconds: float = 15.0,
        busy_seconds: float = 3.0,
        sleep: Callable[[float], None] = time.sleep,
    ):
        self.php = php
        self.handlers = handlers
        self.backend = backend
        self.idle_seconds = idle_seconds
        self.busy_seconds = busy_seconds
        self.sleep = sleep
        self.outage_seconds = busy_seconds

    def run_once(self) -> str:
        """One poll. Returns `idle`, `done`, `failed`, `cancelled` or `php_down`."""

        try:
            job = self.php.claim(VERSION, self.backend)
        except PhpUnavailable as exc:
            log.warning("PHP unavailable while claiming: %s", exc)

            return "php_down"

        if job is None:
            return "idle"

        return self._run(job)

    def _run(self, job: dict) -> str:
        job_id = int(job["id"])
        handler = self.handlers.get(job.get("kind", ""))

        try:
            if handler is None:
                self.php.fail(job_id, "invalid_request", f"No handler for job kind {job.get('kind')!r}.")

                return "failed"

            result = handler(job, JobContext(self.php, job_id))
            self.php.complete(job_id, result)

            return "done"
        except JobCancelled:
            return "cancelled"
        except QbError as exc:
            return self._report_failure(job_id, exc.code, exc.message)
        except PhpUnavailable:
            # Cannot report; the job's heartbeat lapses and PHP re-queues it.
            log.warning("PHP unavailable while running job %s", job_id)

            return "php_down"
        except Exception:
            log.exception("Job %s crashed", job_id)

            return self._report_failure(job_id, "provider_error", "The question builder hit an unexpected error.")

    def _report_failure(self, job_id: int, code: str, message: str) -> str:
        # The code and the provider's own reason; never a key or a source text.
        log.warning("Job %s failed: %s: %s", job_id, code, message)

        try:
            self.php.fail(job_id, code, message)
        except PhpUnavailable:
            log.warning("Could not report failure of job %s", job_id)

        return "failed"

    def run_forever(self, should_stop: Callable[[], bool] = lambda: False) -> None:
        delay = self.busy_seconds

        while not should_stop():
            outcome = self.run_once()

            if outcome == "php_down":
                # Back off while PHP is away, up to a minute.
                delay = min(60.0, max(delay, self.busy_seconds) * 2)
            elif outcome == "idle":
                delay = self.idle_seconds
            else:
                delay = 0.0  # more work may be waiting

            if delay:
                self.sleep(delay)


def main() -> None:  # pragma: no cover - process entry point
    from . import envfile
    from .config import ConfigError, build_worker, from_env

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(name)s %(levelname)s %(message)s")

    # On a developer machine the settings come from python-question-builder-service/.env;
    # anything already in the environment (a service unit's, say) wins over it.
    applied = envfile.load()

    if applied:
        log.info("Read %d setting(s) from the .env file: %s", len(applied), ", ".join(applied))

    try:
        settings = from_env()
    except ConfigError as exc:
        log.error("%s", exc)
        raise SystemExit(2)

    if settings.backend == "groq" and not settings.key_configured:
        log.warning("GROQ_API_KEY is not set: every job will fail with provider_auth until it is.")

    if not settings.php_verify_tls:
        log.warning("Certificate checking for Mentora is OFF (QB_PHP_VERIFY_TLS=false). Use this on staging only.")

    log.info("Worker %s starting (backend %s, models %s)", settings.worker_id, settings.backend, ", ".join(settings.models))

    build_worker(settings).run_forever()


if __name__ == "__main__":  # pragma: no cover
    main()
