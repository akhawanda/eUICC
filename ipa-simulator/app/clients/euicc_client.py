"""
eUICC Client — ES10 interface client for the IPA simulator.

Communicates with the eUICC simulator via REST API, translating
ES10a/ES10b/ES10c function calls into HTTP requests.

In a real device, this would send STORE DATA APDUs over a physical
interface (SPI/I2C/ISO7816). Our simulator uses REST instead.
"""

import base64
import structlog
import httpx

from ..transport.trace import EVENT_HOOKS

logger = structlog.get_logger()


class EuiccClient:
    """
    Client for the eUICC Simulator ES10 interface.

    Wraps all ES10a/b/c function calls as HTTP requests to the
    eUICC simulator service.
    """

    def __init__(self, base_url: str, timeout: float = 30.0):
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.client = httpx.AsyncClient(
            base_url=self.base_url,
            timeout=timeout,
            headers={"Content-Type": "application/json"},
            event_hooks=EVENT_HOOKS,
        )

    async def close(self):
        await self.client.aclose()

    # ==================================================================
    # ES10a — Configuration
    # ==================================================================

    async def get_configured_addresses(self, eid: str) -> dict:
        """ES10a.GetEuiccConfiguredAddresses"""
        resp = await self.client.get(f"/api/es10/{eid}/configured-addresses")
        resp.raise_for_status()
        return resp.json()

    async def set_default_dp_address(self, eid: str, address: str) -> dict:
        """ES10a.SetDefaultDpAddress"""
        resp = await self.client.post(
            f"/api/es10/{eid}/configured-addresses",
            json={"defaultDpAddress": address},
        )
        resp.raise_for_status()
        return resp.json()

    # ==================================================================
    # ES10b — Profile Download & Authentication
    # ==================================================================

    async def get_euicc_info1(self, eid: str) -> dict:
        """ES10b.GetEuiccInfo1"""
        resp = await self.client.get(f"/api/es10/{eid}/euicc-info1")
        resp.raise_for_status()
        return resp.json()

    async def get_euicc_info2(self, eid: str) -> dict:
        """ES10b.GetEuiccInfo2"""
        resp = await self.client.get(f"/api/es10/{eid}/euicc-info2")
        resp.raise_for_status()
        return resp.json()

    async def get_euicc_challenge(self, eid: str) -> dict:
        """ES10b.GetEuiccChallenge"""
        resp = await self.client.post(f"/api/es10/{eid}/euicc-challenge")
        resp.raise_for_status()
        return resp.json()

    async def authenticate_server(
        self,
        eid: str,
        server_signed1: dict,
        server_signature1: str,
        ci_pkid: str,
        server_certificate_b64: str,
        ctx_params1=None,  # tuple (alt_name, value) | dict | None
        server_signed1_raw_b64: str | None = None,
    ) -> dict:
        """ES10b.AuthenticateServer"""
        body = {
            "serverSigned1": server_signed1,
            "serverSignature1": server_signature1,
            "euiccCiPKIdToBeUsed": ci_pkid,
            "serverCertificate": server_certificate_b64,
            "ctxParams1": ctx_params1,
        }
        if server_signed1_raw_b64:
            body["serverSigned1Raw"] = server_signed1_raw_b64
        resp = await self.client.post(
            f"/api/es10/{eid}/authenticate-server",
            json=body,
        )
        resp.raise_for_status()
        return resp.json()

    async def prepare_download(
        self,
        eid: str,
        smdp_signed2: dict,
        smdp_signature2: str,
        hash_cc: str | None = None,
        smdp_certificate_b64: str | None = None,
        smdp_signed2_raw_b64: str | None = None,
    ) -> dict:
        """ES10b.PrepareDownload"""
        body = {
            "smdpSigned2": smdp_signed2,
            "smdpSignature2": smdp_signature2,
            "hashCc": hash_cc,
            "smdpCertificate": smdp_certificate_b64,
        }
        if smdp_signed2_raw_b64:
            body["smdpSigned2Raw"] = smdp_signed2_raw_b64
        resp = await self.client.post(
            f"/api/es10/{eid}/prepare-download",
            json=body,
        )
        resp.raise_for_status()
        return resp.json()

    async def load_bound_profile_package(self, eid: str, bpp: dict) -> dict:
        """ES10b.LoadBoundProfilePackage"""
        resp = await self.client.post(
            f"/api/es10/{eid}/load-bpp",
            json={"boundProfilePackage": bpp},
        )
        resp.raise_for_status()
        return resp.json()

    async def cancel_session(self, eid: str, transaction_id: str, reason: int) -> dict:
        """ES10b.CancelSession"""
        resp = await self.client.post(
            f"/api/es10/{eid}/cancel-session",
            json={"transactionId": transaction_id, "reason": reason},
        )
        resp.raise_for_status()
        return resp.json()

    # ==================================================================
    # ES10c — Local Profile Management
    # ==================================================================

    async def get_profiles_info(self, eid: str) -> dict:
        """ES10c.GetProfilesInfo"""
        resp = await self.client.get(f"/api/es10/{eid}/profiles")
        resp.raise_for_status()
        return resp.json()

    async def enable_profile(self, eid: str, iccid: str) -> dict:
        """ES10c.EnableProfile"""
        resp = await self.client.post(
            f"/api/es10/{eid}/profiles/enable",
            json={"iccid": iccid},
        )
        resp.raise_for_status()
        return resp.json()

    async def disable_profile(self, eid: str, iccid: str) -> dict:
        """ES10c.DisableProfile"""
        resp = await self.client.post(
            f"/api/es10/{eid}/profiles/disable",
            json={"iccid": iccid},
        )
        resp.raise_for_status()
        return resp.json()

    async def delete_profile(self, eid: str, iccid: str) -> dict:
        """ES10c.DeleteProfile"""
        resp = await self.client.post(
            f"/api/es10/{eid}/profiles/delete",
            json={"iccid": iccid},
        )
        resp.raise_for_status()
        return resp.json()

    async def get_eid(self, eid: str) -> dict:
        """ES10c.GetEID"""
        resp = await self.client.get(f"/api/es10/{eid}/eid")
        resp.raise_for_status()
        return resp.json()

    # ==================================================================
    # ES10b IoT — eIM Configuration (SGP.32)
    # ==================================================================

    async def get_eim_config(self, eid: str) -> dict:
        """ES10b-IoT.GetEimConfigurationData"""
        resp = await self.client.get(f"/api/es10/{eid}/eim-config")
        resp.raise_for_status()
        return resp.json()

    async def get_certs(self, eid: str) -> dict:
        """ES10b-IoT.GetCerts"""
        resp = await self.client.get(f"/api/es10/{eid}/certs")
        resp.raise_for_status()
        return resp.json()

    async def load_euicc_package(self, eid: str, package: dict) -> dict:
        """ES10b-IoT.LoadEuiccPackage (ESep relay)"""
        resp = await self.client.post(
            f"/api/es10/{eid}/euicc-package",
            json=package,
        )
        resp.raise_for_status()
        return resp.json()

    async def list_notifications(self, eid: str) -> dict:
        """ES10b.ListNotification"""
        resp = await self.client.get(f"/api/es10/{eid}/notifications")
        resp.raise_for_status()
        return resp.json()
