"""
eIM Client — ESipa interface client for the IPA simulator.

Implements the IPA side of ESipa per SGP.32 §5.14:
- getEimPackage: Poll eIM for pending packages
- provideEimPackageResult: Send operation results back to eIM

The base URL is supplied per-call from the session's eim_fqdn so
different devices can target different eIM servers.
"""

import structlog
import httpx

logger = structlog.get_logger()


class EimFetchError(RuntimeError):
    """Raised when the eIM server returns a non-success HTTP status.

    Carries the status + URL + body so callers can surface the real
    cause in the UI instead of silently reporting 'no package'.
    """

    def __init__(self, status: int, url: str, body: str):
        super().__init__(f"eIM {status} at {url}: {body[:200]}")
        self.status = status
        self.url = url
        self.body = body


class EimClient:
    """
    ESipa client for communicating with an eIM server.

    Single reusable httpx.AsyncClient (no base_url binding) — the
    caller passes the eIM FQDN per request, which means one IPA
    simulator can serve devices associated with different eIMs.
    """

    def __init__(self, default_base_url: str = "", timeout: float = 30.0):
        self.default_base_url = default_base_url.rstrip("/")
        self.client = httpx.AsyncClient(
            timeout=timeout,
            headers={"Content-Type": "application/json"},
        )

    async def close(self):
        await self.client.aclose()

    @staticmethod
    def _normalize(fqdn_or_url: str) -> str:
        """Ensure a scheme is present — default to https per SGP.32."""
        u = (fqdn_or_url or "").strip().rstrip("/")
        if not u:
            return ""
        if u.startswith("http://") or u.startswith("https://"):
            return u
        return f"https://{u}"

    def _base(self, override: str | None) -> str:
        normalized = self._normalize(override or "") or self.default_base_url
        if not normalized:
            raise EimFetchError(0, "", "no eIM URL configured")
        return normalized

    async def _try_paths(self, base: str, paths: list[str], json_body: dict) -> dict:
        last_exc: EimFetchError | None = None
        for path in paths:
            url = f"{base}{path}"
            try:
                resp = await self.client.post(url, json=json_body)
            except httpx.RequestError as e:
                last_exc = EimFetchError(0, url, f"network error: {e}")
                continue
            if resp.is_success:
                return resp.json()
            # 404 means wrong endpoint — fall through and try the next path.
            # Any other status is a real problem and should surface.
            if resp.status_code == 404:
                last_exc = EimFetchError(404, url, resp.text)
                continue
            raise EimFetchError(resp.status_code, url, resp.text)
        # Both endpoints 404 / network-failed — surface the last error.
        if last_exc:
            raise last_exc
        raise EimFetchError(0, base, "no endpoints tried")

    async def get_eim_package(
        self,
        eid: str,
        eim_id: str | None = None,
        base_url: str | None = None,
    ) -> dict:
        """
        ESipa.getEimPackage — Poll eIM for pending packages.

        Response types per SGP.32 §6.4.1.5:
          - euiccPackageRequest: PSMO/eCO operations
          - ipaEuiccDataRequest: Request for eUICC data (scan)
          - profileDownloadTriggerRequest: Trigger profile download
          - eimPackageError: No package available (eimPackageError=1)

        Raises EimFetchError if the eIM is unreachable or returns a
        non-success status, so the caller can distinguish a real
        "no package" response from a transport / auth / 5xx failure.
        """
        return await self._try_paths(
            base=self._base(base_url),
            paths=["/gsma/rsp2/esipa/getEimPackage", "/api/eim/v1/getEIMPackage"],
            json_body={"eidValue": eid},
        )

    async def provide_eim_package_result(
        self,
        eid: str,
        result_data: str,  # base64-encoded DER (BF50)
        base_url: str | None = None,
    ) -> dict:
        """
        ESipa.provideEimPackageResult — Send operation result to eIM.
        """
        return await self._try_paths(
            base=self._base(base_url),
            paths=["/gsma/rsp2/esipa/provideEimPackageResult", "/api/eim/v1/provideEIMPackageResult"],
            json_body={"provideEimPackageResult": result_data},
        )

    async def scan_eim(self, eid: str, base_url: str | None = None) -> dict:
        """Scan endpoint for eIM discovery (Comprion compatibility)."""
        base = self._base(base_url)
        url = f"{base}/api/eim/scan"
        try:
            resp = await self.client.get(url, params={"eid": eid})
        except httpx.RequestError as e:
            raise EimFetchError(0, url, f"network error: {e}")
        if not resp.is_success:
            raise EimFetchError(resp.status_code, url, resp.text)
        return resp.json()
