"""
Binds a fresh TraceContext per /api/ipa/* request and splices the
captured outbound-HTTP steps into the JSON response under the `_trace`
key.  Non-JSON responses are passed through untouched.
"""
from __future__ import annotations

import json

from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import Response

from ..transport.trace import TraceContext, clear_trace, set_trace


# Routes whose responses should carry a _trace.  The poll/download/cancel
# handlers are the ones that talk downstream to eIM/SM-DP+/eUICC.
_TRACED_PREFIXES = (
    "/api/ipa/esipa/",
    "/api/ipa/download/",
)


def _should_trace(path: str) -> bool:
    return any(path.startswith(p) for p in _TRACED_PREFIXES)


class TraceMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        if not _should_trace(request.url.path):
            return await call_next(request)

        trace = TraceContext(origin="ipa")
        holder = set_trace(trace)
        try:
            response = await call_next(request)
        finally:
            # Invalidate for any background tasks that inherited the
            # ContextVar; the holder stays reachable but its .trace is
            # now None, so late hook calls become no-ops.
            clear_trace(holder)

        content_type = response.headers.get("content-type", "")
        if not content_type.startswith("application/json"):
            return response

        body = b""
        async for chunk in response.body_iterator:
            body += chunk
        try:
            data = json.loads(body) if body else None
        except json.JSONDecodeError:
            return Response(
                content=body,
                status_code=response.status_code,
                headers={k: v for k, v in response.headers.items() if k.lower() != "content-length"},
                media_type=content_type,
            )

        if isinstance(data, dict):
            data["_trace"] = trace.steps
        new_body = json.dumps(data).encode("utf-8")

        headers = {
            k: v
            for k, v in response.headers.items()
            if k.lower() not in ("content-length", "content-encoding")
        }
        return Response(
            content=new_body,
            status_code=response.status_code,
            headers=headers,
            media_type="application/json",
        )
