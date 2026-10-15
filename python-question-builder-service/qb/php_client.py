"""The worker's only way to reach Mentora: signed calls to PHP over loopback.
The worker never touches the database."""

import json
import ssl
import urllib.error
import urllib.request
from typing import Callable

SECRET_HEADER = "X-Worker-Secret"

Post = Callable[[str, dict, bytes, float], tuple[int, bytes]]


class PhpUnavailable(Exception):
    """PHP did not answer or refused us; the worker waits and tries again."""


def make_post(verify_tls: bool = True) -> Post:
    """The HTTP call, with certificate checking on unless asked otherwise. It
    is only ever switched off for a staging site whose certificate is
    self-signed and which is reached over loopback."""

    context = None if verify_tls else ssl._create_unverified_context()

    def post(url: str, headers: dict, body: bytes, timeout: float) -> tuple[int, bytes]:
        return urllib_post(url, headers, body, timeout, context=context)

    return post


def urllib_post(url: str, headers: dict, body: bytes, timeout: float, context: ssl.SSLContext | None = None) -> tuple[int, bytes]:
    request = urllib.request.Request(url, data=body, headers=headers, method="POST")

    try:
        with urllib.request.urlopen(request, timeout=timeout, context=context) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read()


class PhpClient:
    def __init__(self, base_url: str, secret: str, worker_id: str, post: Post = urllib_post, timeout: float = 15.0):
        self.base_url = base_url.rstrip("/")
        self.secret = secret
        self.worker_id = worker_id
        self.post = post
        self.timeout = timeout

    def _call(self, path: str, data: dict) -> dict:
        body = json.dumps({"worker_id": self.worker_id, **data}).encode()
        headers = {"Content-Type": "application/json", SECRET_HEADER: self.secret}

        try:
            status, raw = self.post(f"{self.base_url}/internal/ai/{path}", headers, body, self.timeout)
        except Exception as exc:
            raise PhpUnavailable(f"{type(exc).__name__}") from exc

        if status != 200:
            raise PhpUnavailable(f"PHP answered {status}")

        try:
            return json.loads(raw)
        except ValueError as exc:
            raise PhpUnavailable("PHP sent an unreadable reply") from exc

    def claim(self, version: str = "", backend: str = "") -> dict | None:
        return self._call("claim", {"version": version, "backend": backend}).get("job")

    def heartbeat(self, job_id: int | None = None) -> str:
        return self._call("heartbeat", {"job_id": job_id} if job_id else {}).get("state", "unknown")

    def progress(self, job_id: int, **fields) -> str:
        return self._call("progress", {"job_id": job_id, **fields}).get("state", "unknown")

    def complete(self, job_id: int, result: dict) -> bool:
        return bool(self._call("complete", {"job_id": job_id, "result": result}).get("accepted"))

    def fail(self, job_id: int, code: str, message: str = "") -> bool:
        return bool(self._call("fail", {"job_id": job_id, "code": code, "message": message}).get("accepted"))
