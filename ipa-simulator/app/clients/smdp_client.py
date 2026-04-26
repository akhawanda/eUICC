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

from ..transport.trace import EVENT_HOOKS

logger = structlog.get_logger()


def _parse_smdp_signed2(b64: str) -> dict:
    """Parse canonical SGP.22 v3 SmdpSigned2 (base64 DER) into a dict.

    SmdpSigned2 ::= SEQUENCE {
      transactionId   [0] TransactionId,                  -- tag 80
      ccRequiredFlag  BOOLEAN,                            -- tag 01 (universal)
      bppEuiccOtpk    [APPLICATION 73] OCTET STRING OPT,  -- tag 5F49
    }
    Returns hex-encoded transactionId/bppEuiccOtpk so the eUICC sim's
    `_hex_to_bytes_dict` rehydrates them before use.
    """
    der = base64.b64decode(b64)
    if not der or der[0] != 0x30:
        raise ValueError("SmdpSigned2: expected outer SEQUENCE")
    body_len = der[1]
    body_off = 2
    if body_len & 0x80:
        n = body_len & 0x7F
        body_len = int.from_bytes(der[2:2 + n], "big")
        body_off = 2 + n

    out: dict = {"ccRequiredFlag": False}
    pos = body_off
    end = body_off + body_len
    while pos < end:
        first = der[pos]; pos += 1
        if first == 0x5F and pos < end:
            tag = (first << 8) | der[pos]; pos += 1
        else:
            tag = first
        ln = der[pos]; pos += 1
        if ln & 0x80:
            n = ln & 0x7F
            ln = int.from_bytes(der[pos:pos + n], "big")
            pos += n
        v = der[pos:pos + ln]; pos += ln
        if tag == 0x80:
            out["transactionId"] = v.hex()
        elif tag == 0x01:
            out["ccRequiredFlag"] = bool(v[0] if v else 0)
        elif tag == 0x5F49:
            out["bppEuiccOtpk"] = v.hex()
    return out


def _parse_server_signed1(b64: str) -> dict:
    """Parse canonical SGP.22 v3 ServerSigned1 (base64 DER) into a dict
    the eUICC sim's es10b.authenticate_server expects.

    ServerSigned1 ::= SEQUENCE {
      transactionId   [0] TransactionId,    -- tag 80
      euiccChallenge  [1] Octet16,          -- tag 81
      serverAddress   [3] UTF8String,       -- tag 83  (NOT 82 — v3 leaves [2] reserved)
      serverChallenge [4] Octet16           -- tag 84  (NOT 83)
    }
    Verified against SM-DP+ rsp.asn and a live Comprion AuthenticateClient
    capture. Returns hex-encoded bytes for binary fields; the sim's
    `_hex_to_bytes_dict` rehydrates them before signature verification.
    """
    der = base64.b64decode(b64)
    if not der or der[0] != 0x30:
        raise ValueError("ServerSigned1: expected outer SEQUENCE")
    body_len = der[1]
    body_off = 2
    if body_len & 0x80:
        n = body_len & 0x7F
        body_len = int.from_bytes(der[2:2 + n], "big")
        body_off = 2 + n

    out: dict = {}
    pos = body_off
    end = body_off + body_len
    while pos < end:
        tag = der[pos]; pos += 1
        ln = der[pos]; pos += 1
        if ln & 0x80:
            n = ln & 0x7F
            ln = int.from_bytes(der[pos:pos + n], "big")
            pos += n
        v = der[pos:pos + ln]; pos += ln
        if tag == 0x80:
            out["transactionId"] = v.hex()
        elif tag == 0x81:
            out["euiccChallenge"] = v.hex()
        elif tag == 0x83:
            out["serverAddress"] = v.decode("utf-8", errors="replace")
        elif tag == 0x84:
            out["serverChallenge"] = v.hex()
    return out


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
            event_hooks=EVENT_HOOKS,
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

        Per SGP.22 §5.6.1, binary fields on the JSON wire are base64-
        encoded. The eUICC sim hands us hex strings internally; convert
        here so the SM-DP+ (which always base64-decodes) sees the right
        bytes.
        """
        try:
            challenge_bytes = bytes.fromhex(euicc_challenge)
            challenge_b64 = base64.b64encode(challenge_bytes).decode("ascii")
        except Exception:
            challenge_b64 = euicc_challenge  # already base64
        resp = await self.client.post(
            "/gsma/rsp2/es9plus/initiateAuthentication",
            json={
                "euiccChallenge": challenge_b64,
                "euiccInfo1": euicc_info1,
                "smdpAddress": smdp_address,
            },
        )
        resp.raise_for_status()
        result = resp.json()

        # Re-shape serverSigned1 (base64 DER per SGP.22 wire) into the dict
        # the eUICC sim's authenticate_server endpoint expects. Keep the
        # original bytes around for any downstream signature checks.
        if isinstance(result.get("serverSigned1"), str):
            try:
                result["serverSigned1Raw"] = result["serverSigned1"]
                result["serverSigned1"] = _parse_server_signed1(result["serverSigned1"])
            except Exception as e:
                logger.warning("server_signed1_parse_failed", error=str(e))

        # eUICC sim expects hex for raw byte fields; SM-DP+ wire is base64.
        # serverSignature1 arrives wrapped as 5F37 [APPLICATION 55] OCTET STRING
        # (TLV: `5F 37 40 <64 bytes>` = 67 bytes total). The eUICC's ECDSA verify
        # expects the raw 64-byte r||s, so strip the wrapper here.
        for field in ("serverSignature1", "euiccCiPKIdToBeUsed"):
            v = result.get(field)
            if isinstance(v, str) and v:
                try:
                    raw = base64.b64decode(v)
                    if field == "serverSignature1" and len(raw) == 67 and raw[:2] == b"\x5F\x37":
                        raw = raw[3:]  # drop tag(2) + length(1)
                    result[field] = raw.hex()
                except Exception:
                    pass

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
        euicc_signed1_raw_hex: str = "",  # hex of canonical EuiccSigned1 DER as the eUICC signed it
    ) -> dict:
        """
        ES9+.AuthenticateClient — Complete authentication with eUICC proof.

        Per SGP.22 §5.6.2 the SM-DP+ expects `authenticateServerResponse`
        as base64-encoded DER of AuthenticateServerResponse [56] CHOICE
        (BF38). The eUICC sim already produced canonical EuiccSigned1 DER
        (the bytes it signed), so we hand-roll the surrounding wrapper at
        the byte level — single source of truth, no schema-level coercion
        of every nested field.

        Wire shape:
          BF38 ll
            A0 ll                                    -- CHOICE alt 0 (AuthenticateResponseOk)
              <EuiccSigned1 raw DER>                 -- a 30-tagged SEQUENCE from the eUICC
              5F37 40 <64B raw r||s>                 -- euiccSignature1
              <euiccCertificate DER>                 -- a 30-tagged SEQUENCE
              <eumCertificate DER>                   -- a 30-tagged SEQUENCE
        """
        from ..services.asn1_codec import _tlv

        es1_raw = bytes.fromhex(euicc_signed1_raw_hex) if euicc_signed1_raw_hex else b""
        sig_bytes = bytes.fromhex(euicc_signature1) if isinstance(euicc_signature1, str) else (euicc_signature1 or b"")
        # The eUICC sim's route serialises bytes via `_bytes_to_hex`, so what
        # arrives here is HEX text (not base64). Accept either: prefer hex if
        # the string is all-hex (a real DER cert always has non-hex base64
        # padding chars or `/` `+`).
        def _to_der(s: str) -> bytes:
            if not s:
                return b""
            try:
                return bytes.fromhex(s)
            except ValueError:
                return base64.b64decode(s)
        euicc_cert_der = _to_der(euicc_certificate)
        eum_cert_der = _to_der(eum_certificate or "")

        sig_tlv = _tlv(0x5F37, sig_bytes)
        ok_body = es1_raw + sig_tlv + euicc_cert_der + eum_cert_der
        a0 = _tlv(0xA0, ok_body)
        bf38 = _tlv(0xBF38, a0)
        asr_b64 = base64.b64encode(bf38).decode("ascii")

        resp = await self.client.post(
            "/gsma/rsp2/es9plus/authenticateClient",
            json={
                "transactionId": transaction_id,
                "authenticateServerResponse": asr_b64,
            },
        )
        resp.raise_for_status()
        result = resp.json()

        # Re-shape smdpSigned2 (base64 DER) into the dict the eUICC sim
        # expects, plus stash the raw bytes for signature verification.
        if isinstance(result.get("smdpSigned2"), str):
            try:
                result["smdpSigned2Raw"] = result["smdpSigned2"]
                result["smdpSigned2"] = _parse_smdp_signed2(result["smdpSigned2"])
            except Exception as e:
                logger.warning("smdp_signed2_parse_failed", error=str(e))

        # smdpSignature2 wire form is `5F37 40 <64 bytes>` per SGP.22 §2.6.7.2.
        v = result.get("smdpSignature2")
        if isinstance(v, str) and v:
            try:
                raw = base64.b64decode(v)
                if len(raw) == 67 and raw[:2] == b"\x5F\x37":
                    raw = raw[3:]
                result["smdpSignature2"] = raw.hex()
            except Exception:
                pass

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
