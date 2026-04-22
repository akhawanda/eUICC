"""
ASN.1 DER Codec for IPA Simulator.

Provides encoding of ESipa result messages (BF50/BF52/BF54)
that the IPA sends back to the eIM server.
"""

from __future__ import annotations

import base64
from pathlib import Path
from functools import lru_cache

import asn1tools
import structlog

logger = structlog.get_logger()

SCHEMA_PATH = Path(__file__).parent.parent.parent / "asn1_schemas" / "rsp_definitions.asn"


@lru_cache(maxsize=1)
def _compile_schema() -> asn1tools.CompiledFile:
    schema = asn1tools.compile_files(str(SCHEMA_PATH), "der")
    logger.info("asn1_schema_compiled", types=len(schema.types))
    return schema


# ---------------------------------------------------------------------------
# Minimal BER/DER TLV walker for decoding EuiccPackageRequest from eIM.
#
# asn1tools would need the full PSMO/eCO type tree to decode; the eIM's
# Go encoder emits a narrow, well-documented subset, so a targeted TLV
# walk is both simpler and more tolerant of schema drift.
# ---------------------------------------------------------------------------


def _read_tag(buf: bytes, pos: int) -> tuple[int, int]:
    first = buf[pos]
    pos += 1
    if (first & 0x1F) != 0x1F:
        return first, pos
    tag = first
    while True:
        b = buf[pos]
        pos += 1
        tag = (tag << 8) | b
        if not (b & 0x80):
            break
    return tag, pos


def _read_len(buf: bytes, pos: int) -> tuple[int, int]:
    b = buf[pos]
    pos += 1
    if not (b & 0x80):
        return b, pos
    n = b & 0x7F
    if n == 0:
        raise ValueError("indefinite length not supported in DER")
    return int.from_bytes(buf[pos:pos + n], "big"), pos + n


def _read_tlv(buf: bytes, pos: int) -> tuple[int, bytes, int]:
    tag, pos = _read_tag(buf, pos)
    length, pos = _read_len(buf, pos)
    end = pos + length
    return tag, buf[pos:end], end


def _walk(buf: bytes):
    """Iterate TLVs concatenated in a buffer, yielding (tag, value)."""
    pos = 0
    while pos < len(buf):
        tag, v, pos = _read_tlv(buf, pos)
        yield tag, v


class Asn1Codec:
    """ASN.1 DER codec for IPA simulator ESipa messages."""

    # PSMO tag → action name consumed by the eUICC sim's _execute_psmo
    _PSMO_ACTIONS = {
        0xA3: "enable",
        0xA4: "disable",
        0xA5: "delete",
        0xA6: "getRAT",
        0xA7: "configureImmediateEnable",
        0xA8: "setFallbackAttribute",
        0xA9: "unsetFallbackAttribute",
        0xBF2D: "listProfileInfo",
        0xBF65: "setDefaultDpAddress",
    }
    # eCO tag → action name consumed by the eUICC sim's _execute_eco
    _ECO_ACTIONS = {
        0xA8: "addEim",
        0xA9: "deleteEim",
        0xAA: "updateEim",
        0xAB: "listEim",
    }

    def __init__(self):
        self.schema = _compile_schema()

    def decode_euicc_package_request(self, der: bytes) -> dict:
        """Decode a signed EuiccPackageRequest (BF51) into the dict shape
        the eUICC sim's /api/es10/{eid}/euicc-package endpoint expects:
        ``{eimId, counterValue, psmoList, ecoList}``.

        Signature verification is skipped — the eUICC sim does not enforce
        it and our integration value is protocol-level, not crypto-level.
        """
        tag, inner, _ = _read_tlv(der, 0)
        if tag != 0xBF51:
            raise ValueError(f"expected BF51 EuiccPackageRequest, got {tag:#X}")

        sub_tag, signed_body, _ = _read_tlv(inner, 0)
        if sub_tag != 0x30:
            raise ValueError(f"expected SEQUENCE EuiccPackageSigned, got {sub_tag:#X}")

        out: dict = {
            "eimId": "",
            "counterValue": 0,
            "psmoList": [],
            "ecoList": [],
        }
        for t, v in _walk(signed_body):
            if t == 0x80:
                out["eimId"] = v.decode("utf-8", errors="replace")
            elif t == 0x5A:
                out["eidValue"] = v.hex().upper()
            elif t == 0x81:
                out["counterValue"] = int.from_bytes(v, "big") if v else 0
            elif t == 0x82:
                out["transactionId"] = v.hex().upper()
            elif t == 0xA0:
                out["psmoList"] = [self._decode_psmo(pt, pv) for pt, pv in _walk(v)]
            elif t == 0xA1:
                out["ecoList"] = [self._decode_eco(et, ev) for et, ev in _walk(v)]
        return out

    def _decode_psmo(self, tag: int, value: bytes) -> dict:
        action = self._PSMO_ACTIONS.get(tag, f"unknownPsmo_{tag:X}")
        out = {"action": action}
        if action in ("enable", "disable", "delete", "setFallbackAttribute",
                      "configureImmediateEnable"):
            for sub_tag, sub_value in _walk(value):
                if sub_tag == 0x5A:
                    out["iccid"] = sub_value.hex().upper()
                    break
        return out

    def _decode_eco(self, tag: int, value: bytes) -> dict:
        action = self._ECO_ACTIONS.get(tag, f"unknownEco_{tag:X}")
        out = {"action": action}
        if action == "deleteEim":
            for sub_tag, sub_value in _walk(value):
                if sub_tag == 0x80:
                    out["eimId"] = sub_value.decode("utf-8", errors="replace")
        elif action == "addEim":
            cfg: dict = {}
            for sub_tag, sub_value in _walk(value):
                if sub_tag == 0x80:
                    cfg["eimId"] = sub_value.decode("utf-8", errors="replace")
                elif sub_tag == 0x81:
                    cfg["eimFqdn"] = sub_value.decode("utf-8", errors="replace")
                elif sub_tag == 0x82:
                    cfg["eimIdType"] = int.from_bytes(sub_value, "big") if sub_value else 0
                elif sub_tag == 0x83:
                    cfg["counterValue"] = int.from_bytes(sub_value, "big") if sub_value else 0
                elif sub_tag == 0x84:
                    cfg["associationToken"] = int.from_bytes(sub_value, "big", signed=True) if sub_value else -1
            out["eimConfig"] = cfg
        elif action == "updateEim":
            for sub_tag, sub_value in _walk(value):
                if sub_tag == 0x80:
                    out["eimId"] = sub_value.decode("utf-8", errors="replace")
        return out

    def encode(self, type_name: str, data) -> bytes:
        return self.schema.encode(type_name, data)

    def encode_base64(self, type_name: str, data) -> str:
        return base64.b64encode(self.encode(type_name, data)).decode("ascii")

    def encode_provide_eim_package_result(self, data: tuple) -> str:
        """
        Encode ProvideEimPackageResult to base64 DER for sending to eIM.

        Args:
            data: CHOICE tuple, e.g.:
                ('euiccPackageResult', {...})
                ('ipaEuiccDataResponse', {...})
                ('profileDownloadTriggerResult', {...})
                ('eimAcknowledgements', {...})
        """
        der = self.schema.encode("ProvideEimPackageResult", data)
        return base64.b64encode(der).decode("ascii")

    def encode_ipa_euicc_data_response(
        self,
        eid: bytes,
        profiles: list[dict],
        eim_configs: list[dict],
        eum_cert: bytes | None = None,
        euicc_cert: bytes | None = None,
    ) -> str:
        """
        Encode IpaEuiccDataResponse (BF52) as base64 DER.

        This is the response to an ipaEuiccDataRequest — the eIM
        asked for a scan of the eUICC's current state.
        """
        response = {}
        if eid:
            response["eid"] = eid
        if profiles:
            response["profileInfoList"] = profiles
        if eim_configs:
            response["eimConfigList"] = eim_configs
        if eum_cert:
            response["eumCertificate"] = eum_cert
        if euicc_cert:
            response["euiccCertificate"] = euicc_cert

        return self.encode_provide_eim_package_result(
            ("ipaEuiccDataResponse", response)
        )

    def encode_profile_download_trigger_result(
        self,
        eim_transaction_id: bytes | None = None,
        error_code: int | None = None,
    ) -> str:
        """
        Encode ProfileDownloadTriggerResult (BF54) as base64 DER.

        This is the response after a profile download trigger.
        """
        result = {}
        if eim_transaction_id:
            result["eimTransactionId"] = eim_transaction_id
        if error_code is not None:
            result["errorCode"] = error_code

        return self.encode_provide_eim_package_result(
            ("profileDownloadTriggerResult", result)
        )

    def encode_euicc_package_result(self, result_data: bytes) -> str:
        """
        Encode EuiccPackageResult (BF50/BF51) as base64 DER.

        Wraps the raw result from eUICC package processing.
        """
        return self.encode_provide_eim_package_result(
            ("euiccPackageResult", {
                "euiccPackageResultSigned": result_data,
            })
        )

    def encode_eim_acknowledgements(self, seq_numbers: list[int]) -> str:
        """Encode EimAcknowledgements as base64 DER."""
        return self.encode_provide_eim_package_result(
            ("eimAcknowledgements", {"seqNumbers": seq_numbers})
        )
