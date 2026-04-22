"""
Per-request trace recorder.

httpx event hooks installed on each outbound client (eIM, SM-DP+, eUICC)
write request/response pairs into whatever TraceContext is bound to the
current asyncio task via a ContextVar.  The TraceMiddleware binds a fresh
context for each incoming /api/ipa/* request and splices the recorded
steps into the JSON response as `_trace`, so the Laravel dashboard can
render the full cross-actor flow.
"""
from __future__ import annotations

import contextvars
import time
from dataclasses import dataclass, field
from typing import Optional

import httpx


@dataclass
class TraceContext:
    origin: str = "ipa"
    steps: list[dict] = field(default_factory=list)
    _starts: dict[int, float] = field(default_factory=dict)


class _TraceHolder:
    """Mutable holder so the middleware can null the trace out for any
    background tasks that inherited the ContextVar when the request
    finished — prevents late-arriving hook calls from mutating a
    context the client has already walked away from."""

    __slots__ = ("trace",)

    def __init__(self, trace: Optional[TraceContext]):
        self.trace: Optional[TraceContext] = trace


_holder_ctx: contextvars.ContextVar[Optional[_TraceHolder]] = contextvars.ContextVar(
    "ipa_trace_holder", default=None
)


def current_trace() -> Optional[TraceContext]:
    h = _holder_ctx.get()
    return h.trace if h else None


def set_trace(trace: Optional[TraceContext]) -> _TraceHolder:
    holder = _TraceHolder(trace)
    _holder_ctx.set(holder)
    return holder


def clear_trace(holder: _TraceHolder) -> None:
    holder.trace = None


def _actor_for_url(url: str) -> str:
    u = url.lower()
    if "/api/es10/" in u or "/api/management/" in u or ":8100" in u:
        return "euicc"
    if "/gsma/rsp2/esipa/" in u or "/api/eim/" in u or "eim.connectxiot.com" in u or "eimserver." in u:
        return "eim"
    if "/gsma/rsp2/es9plus/" in u or "smdp" in u:
        return "smdpplus"
    return "external"


def _decode(body: bytes | None) -> Optional[str]:
    if not body:
        return None
    try:
        return body.decode("utf-8")
    except UnicodeDecodeError:
        return body.hex()


async def _on_request(request: httpx.Request) -> None:
    trace = current_trace()
    if trace is None:
        return
    try:
        await request.aread()
    except Exception:
        pass
    actor_to = _actor_for_url(str(request.url))
    trace._starts[id(request)] = time.perf_counter()
    trace.steps.append(
        {
            "direction": "request",
            "actor_from": trace.origin,
            "actor_to": actor_to,
            "method": request.method,
            "endpoint": str(request.url.path),
            "http_headers": dict(request.headers),
            "http_body": _decode(request.content),
        }
    )


async def _on_response(response: httpx.Response) -> None:
    trace = current_trace()
    if trace is None:
        return
    try:
        await response.aread()
    except Exception:
        pass
    request = response.request
    start = trace._starts.pop(id(request), None)
    ms = int((time.perf_counter() - start) * 1000) if start is not None else None
    actor_from = _actor_for_url(str(request.url))
    trace.steps.append(
        {
            "direction": "response",
            "actor_from": actor_from,
            "actor_to": trace.origin,
            "method": request.method,
            "endpoint": str(request.url.path),
            "http_status": response.status_code,
            "http_headers": dict(response.headers),
            "http_body": _decode(response.content),
            "response_time_ms": ms,
        }
    )


#: Pass this dict as `event_hooks=` when constructing an httpx.AsyncClient
#: so every outbound request/response is captured into the current
#: ContextVar-bound TraceContext (if any).
EVENT_HOOKS: dict[str, list] = {
    "request": [_on_request],
    "response": [_on_response],
}
