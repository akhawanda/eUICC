"""
SM-DP+ Client — ES9+ interface client for the IPA simulator.

Implements the IPA side of ES9+ per SGP.22 §5.6:
- InitiateAuthentication: Start mutual authentication
- AuthenticateClient: Complete authentication with eUICC proof
- GetBoundProfilePackage: Download the encrypted profile
- HandleNotification: Send notifications to SM-DP+

Connects to Ahmed's existing SM-DP+ server (smdpplus.connectxiot.com).
"""

import base64
import structlog
import httpx

logger = structlog.get_logger()


class SmdpClient:
    """
    ES9+ client for communicating with the SM-DP+ server.

    Implements the IPA's role in the ES9+ interface for the
    profile download authentication and download flow.
    """

    def __init__(self, base_url: str, timeout: float = 60.0):
        self.base_url = base_url.rstrip("/")
        self.client = httpx.AsyncClient(
            base_url=self.base_url,
            timeout=timeout,
            headers={"Content-Type": "application/json"},
        )

    async def close(self):
        await self.client.aclose()

    async def initiate_authentication(
        self,
        euicc_challenge: str,
        euicc_info1: dict,
        smdp_address: str,
    ) -> dict:
        """
        ES9+.InitiateAuthentication — Start mutual authentication.

        The IPA sends the eUICC's challenge and info to the SM-DP+,
        which responds with its own challenge and signed data.

        Per SGP.22 §5.6.1
        """
        resp = await self.client.post(
            "/gsma/rsp2/es9plus/initiateAuthentication",
            json={
                "euiccChallenge": euicc_challenge,
                "euiccInfo1": euicc_info1,
                "smdpAddress": smdp_address,
            },
        )
        resp.raise_for_status()
        result = resp.json()

        logger.info(
            "es9p_initiate_auth",
            has_server_signed1="serverSigned1" in result,
            has_server_cert="serverCertificate" in result,
        )
        return result

    async def authenticate_client(
        self,
        transaction_id: str,
        euicc_signed1: dict,
        euicc_signature1: str,
        euicc_certificate: str,  # base64 DER
        eum_certificate: str | None = None,  # base64 DER
    ) -> dict:
        """
        ES9+.AuthenticateClient — Complete authentication with eUICC proof.

        The IPA sends the eUICC's signed response to the SM-DP+,
        which verifies it and prepares the profile for download.

        Per SGP.22 §5.6.2
        """
        resp = await self.client.post(
            "/gsma/rsp2/es9plus/authenticateClient",
            json={
                "transactionId": transaction_id,
                "authenticateServerResponse": {
                    "authenticateResponseOk": {
                        "euiccSigned1": euicc_signed1,
                        "euiccSignature1": euicc_signature1,
                        "euiccCertificate": euicc_certificate,
                        "eumCertificate": eum_certificate,
                    }
                },
            },
        )
        resp.raise_for_status()
        result = resp.json()

        logger.info(
            "es9p_authenticate_client",
            has_smdp_signed2="smdpSigned2" in result,
            has_profile_metadata="profileMetadata" in result,
        )
        return result

    async def get_bound_profile_package(
        self,
        transaction_id: str,
        euicc_signed2: dict,
        euicc_signature2: str,
    ) -> dict:
        """
        ES9+.GetBoundProfilePackage — Download the encrypted profile.

        The IPA sends the eUICC's PrepareDownload response to get
        the actual Bound Profile Package.

        Per SGP.22 §5.6.3
        """
        resp = await self.client.post(
            "/gsma/rsp2/es9plus/getBoundProfilePackage",
            json={
                "transactionId": transaction_id,
                "prepareDownloadResponse": {
                    "downloadResponseOk": {
                        "euiccSigned2": euicc_signed2,
                        "euiccSignature2": euicc_signature2,
                    }
                },
            },
        )
        resp.raise_for_status()
        result = resp.json()

        logger.info(
            "es9p_get_bpp",
            has_bpp="boundProfilePackage" in result,
        )
        return result

    async def handle_notification(
        self,
        notification_address: str,
        pending_notification: str,  # base64-encoded
    ) -> dict:
        """
        ES9+.HandleNotification — Send notification to SM-DP+.

        After profile install/enable/disable/delete, the IPA sends
        the signed notification to the SM-DP+ for confirmation.

        Per SGP.22 §5.6.4
        """
        # Notification goes to the address in the notification metadata
        try:
            resp = await self.client.post(
                "/gsma/rsp2/es9plus/handleNotification",
                json={
                    "pendingNotification": pending_notification,
                },
            )
            resp.raise_for_status()
            return resp.json()
        except httpx.HTTPStatusError as e:
            logger.warning(
                "notification_failed",
                address=notification_address,
                status=e.response.status_code,
            )
            return {"error": str(e)}

    async def cancel_session(
        self,
        transaction_id: str,
        cancel_response: dict,
    ) -> dict:
        """
        ES9+.CancelSession — Forward session cancellation to SM-DP+.

        Per SGP.22 §5.6.5
        """
        resp = await self.client.post(
            "/gsma/rsp2/es9plus/cancelSession",
            json={
                "transactionId": transaction_id,
                "cancelSessionResponse": cancel_response,
            },
        )
        resp.raise_for_status()
        return resp.json()
