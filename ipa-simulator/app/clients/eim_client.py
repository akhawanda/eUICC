"""
eIM Client — ESipa interface client for the IPA simulator.

Implements the IPA side of ESipa per SGP.32 §5.14:
- getEimPackage: Poll eIM for pending packages
- provideEimPackageResult: Send operation results back to eIM

Connects to Ahmed's existing eIM server (eim.connectxiot.com).
"""

import base64
import structlog
import httpx

logger = structlog.get_logger()


class EimClient:
    """
    ESipa client for communicating with the eIM server.

    Implements the IPA's role in the ESipa interface:
    - Poll for eIM packages (PSMO/eCO/scan/profile download triggers)
    - Return operation results
    """

    def __init__(self, base_url: str, timeout: float = 30.0):
        self.base_url = base_url.rstrip("/")
        self.client = httpx.AsyncClient(
            base_url=self.base_url,
            timeout=timeout,
            headers={"Content-Type": "application/json"},
        )

    async def close(self):
        await self.client.aclose()

    async def get_eim_package(self, eid: str, eim_id: str | None = None) -> dict:
        """
        ESipa.getEimPackage — Poll eIM for pending packages.

        Per SGP.32 §6.4.1.5, the IPA polls the eIM to check
        if there are any pending operations for this eUICC.

        Response types:
        - euiccPackageRequest: PSMO/eCO operations
        - ipaEuiccDataRequest: Request for eUICC data (scan)
        - profileDownloadTriggerRequest: Trigger profile download
        - eimPackageError: No package available
        """
        # Try GSMA-compliant endpoint first
        try:
            resp = await self.client.post(
                "/gsma/rsp2/esipa/getEimPackage",
                json={
                    "eid": eid,
                    "eimId": eim_id,
                },
            )
            resp.raise_for_status()
            return resp.json()
        except httpx.HTTPStatusError:
            pass

        # Fall back to legacy endpoint
        try:
            resp = await self.client.post(
                "/api/eim/v1/getEIMPackage",
                json={
                    "eid": eid,
                    "eimId": eim_id,
                },
            )
            resp.raise_for_status()
            return resp.json()
        except httpx.HTTPStatusError as e:
            logger.warning("eim_package_fetch_failed", status=e.response.status_code)
            return {"eimPackageError": 1}  # noEimPackageAvailable

    async def provide_eim_package_result(
        self,
        eid: str,
        result_data: str,  # base64-encoded DER (BF50)
    ) -> dict:
        """
        ESipa.provideEimPackageResult — Send operation result to eIM.

        Per SGP.32 §5.14.6, the IPA sends the eUICC's response
        back to the eIM after executing operations.
        """
        # Try GSMA-compliant endpoint
        try:
            resp = await self.client.post(
                "/gsma/rsp2/esipa/provideEimPackageResult",
                json={
                    "eid": eid,
                    "provideEimPackageResultData": result_data,
                },
            )
            resp.raise_for_status()
            return resp.json()
        except httpx.HTTPStatusError:
            pass

        # Fall back to legacy endpoint
        try:
            resp = await self.client.post(
                "/api/eim/v1/provideEIMPackageResult",
                json={
                    "eid": eid,
                    "provideEimPackageResultData": result_data,
                },
            )
            resp.raise_for_status()
            return resp.json()
        except httpx.HTTPStatusError as e:
            logger.error("eim_result_send_failed", status=e.response.status_code)
            return {"error": str(e)}

    async def scan_eim(self, eid: str) -> dict:
        """
        Scan endpoint for eIM discovery (Comprion compatibility).

        Returns eIM configuration for the given EID.
        """
        try:
            resp = await self.client.get(
                "/api/eim/scan",
                params={"eid": eid},
            )
            resp.raise_for_status()
            return resp.json()
        except httpx.HTTPStatusError as e:
            logger.warning("eim_scan_failed", status=e.response.status_code)
            return {"error": "scan_failed"}
