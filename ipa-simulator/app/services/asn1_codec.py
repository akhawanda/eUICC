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


def _enc_len(n: int) -> bytes:
    if n < 0x80:
        return bytes([n])
    raw = n.to_bytes((n.bit_length() + 7) // 8, "big")
    return bytes([0x80 | len(raw)]) + raw


def _enc_tag(tag: int) -> bytes:
    if tag <= 0xFF:
        return bytes([tag])
    n = (tag.bit_length() + 7) // 8
    return tag.to_bytes(n, "big")


def _tlv(tag: int, value: bytes) -> bytes:
    return _enc_tag(tag) + _enc_len(len(value)) + value


def _enc_uint(n: int) -> bytes:
    """Minimal unsigned-integer encoding (no leading 0x00 unless needed for sign)."""
    if n == 0:
        return b"\x00"
    raw = n.to_bytes((n.bit_length() + 7) // 8, "big")
    if raw[0] & 0x80:
        raw = b"\x00" + raw
    return raw


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

    def decode_profile_download_trigger_request(self, der: bytes) -> dict:
        """Decode BF54 ProfileDownloadTriggerRequest into a flat dict.

        Wire shape per eIM-go encoder:
          BF54 -> A0 (profileDownloadData) -> 80 activationCode (UTF-8)
                  82 eimTransactionId (16B)

        Returns ``{activationCode, smdpAddress, matchingId, transactionId}``.
        smdpAddress + matchingId are extracted from the LPA AC string
        (`<v>$<smdp>$<matching>[$<cc>][$<oid>]`).
        """
        tag, inner, _ = _read_tlv(der, 0)
        if tag != 0xBF54:
            raise ValueError(f"expected BF54, got {tag:#X}")

        out = {"activationCode": "", "smdpAddress": "", "matchingId": "", "transactionId": ""}
        for t, v in _walk(inner):
            if t == 0xA0:
                for st, sv in _walk(v):
                    if st == 0x80:
                        out["activationCode"] = sv.decode("utf-8", errors="replace")
            elif t == 0x82:
                out["transactionId"] = v.hex().upper()

        if out["activationCode"]:
            parts = out["activationCode"].split("$")
            if len(parts) >= 3:
                out["smdpAddress"] = parts[1]
                out["matchingId"] = parts[2]
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
        eid_hex: str | None = None,
    ) -> str:
        """Encode ProvideEimPackageResult > profileDownloadTriggerResult as base64 DER.

        Wire shape eIM-go's decoder reads:
          BF50 <len>
            5A 10 <eid>                     -- required for finishQueueItem
            BF54 <len>                      -- profileDownloadTriggerResult
              82 10 <transactionId 16B>     -- echoed
              [A1 ... 80 errorCode]         -- only on failure (scanForDownloadError)
        """
        body = b""
        if eim_transaction_id:
            tx = eim_transaction_id if isinstance(eim_transaction_id, (bytes, bytearray)) else bytes.fromhex(eim_transaction_id)
            body += _tlv(0x82, tx)
        if error_code is not None:
            err_inner = _tlv(0x80, _enc_uint(error_code))
            body += _tlv(0xA1, err_inner)
        bf54 = _tlv(0xBF54, body)
        outer = b""
        if eid_hex:
            outer += _tlv(0x5A, bytes.fromhex(eid_hex))
        outer += bf54
        bf50 = _tlv(0xBF50, outer)
        return base64.b64encode(bf50).decode("ascii")

    # Per SGP.32 §5.9.5 ProfileManagementOperationResult:
    #   ok=0, iccidOrAidNotFound=1, profileNotInDisabledState=2,
    #   profileNotInEnabledState=3, catBusy=5, disallowedByPolicy=6,
    #   wrongProfileReenabling=7, commandError/undefinedError=127.
    _PSMO_RESULT_CODES = {
        "ok": 0,
        "iccidNotFound": 1,
        "alreadyEnabled": 2,
        "notEnabled": 3,
        "mustDisableFirst": 7,
    }

    def encode_euicc_package_result(
        self,
        eim_id: str,
        counter_value: int,
        transaction_id_hex: str | None,
        seq_number: int,
        operation_results: list[dict],
        eid_hex: str | None = None,
    ) -> str:
        """Encode ProvideEimPackageResult > euiccPackageResult > A0 signed.

        Wire shape mirroring the eIM Go decoder's expectations:
          BF50 -> 5A eidValue + BF51 -> A0 -> 30 SEQUENCE {
            80 eimId, 81 counterValue, 82 txId?, 83 seqNumber, <opResults...>
          }
        eidValue (tag 5A, APPLICATION 26) is required by eIM-go's finishQueueItem
        for queue lookup — without it the queue row stays `queued` even on
        Executed-Success.
        Signature is omitted (sim is a protocol surface, not crypto).
        """
        body = b""
        body += _tlv(0x80, eim_id.encode("utf-8"))
        body += _tlv(0x81, _enc_uint(counter_value))
        if transaction_id_hex:
            body += _tlv(0x82, bytes.fromhex(transaction_id_hex))
        body += _tlv(0x83, _enc_uint(seq_number))
        for r in operation_results:
            body += self._encode_op_result(r)

        signed = _tlv(0x30, body)
        bf51 = _tlv(0xBF51, _tlv(0xA0, signed))
        outer = b""
        if eid_hex:
            outer += _tlv(0x5A, bytes.fromhex(eid_hex))
        outer += bf51
        bf50 = _tlv(0xBF50, outer)
        return base64.b64encode(bf50).decode("ascii")

    def _encode_op_result(self, r: dict) -> bytes:
        action = r.get("action", "")
        code = self._PSMO_RESULT_CODES.get(r.get("result", ""), 127)

        if action == "enable":
            return _tlv(0x83, bytes([code]))
        if action == "disable":
            return _tlv(0x84, bytes([code]))
        if action == "delete":
            return _tlv(0x85, bytes([code]))
        if action == "getRAT":
            return _tlv(0x86, b"")
        if action == "listProfileInfo":
            entries = b"".join(self._encode_profile_info(p) for p in r.get("profiles", []))
            return _tlv(0xBF2D, _tlv(0xA0, entries))
        if action == "addEim":
            inner = b""
            token = r.get("associationToken")
            if token is not None:
                inner += _tlv(0x84, _enc_uint(token))
            inner += _tlv(0x02, bytes([r.get("addEimResult", 0)]))
            return _tlv(0xA8, inner)
        if action == "deleteEim":
            return _tlv(0x89, bytes([r.get("deleteEimResult", 0)]))
        if action == "updateEim":
            return _tlv(0x8A, bytes([r.get("updateEimResult", 0)]))
        if action == "listEim":
            entries = b""
            for cfg in r.get("eimConfigurationDataList", []):
                ec = b""
                ec += _tlv(0x80, cfg.get("eimId", "").encode("utf-8"))
                if cfg.get("eimFqdn"):
                    ec += _tlv(0x81, cfg["eimFqdn"].encode("utf-8"))
                entries += _tlv(0xA0, ec)
            return _tlv(0xBB, entries)
        return b""

    def _encode_profile_info(self, p: dict) -> bytes:
        body = b""
        iccid_hex = p.get("iccid", "")
        if iccid_hex:
            body += _tlv(0x5A, bytes.fromhex(iccid_hex))
        state = 1 if p.get("state") == "enabled" else 0
        body += _tlv(0x9F70, bytes([state]))
        if p.get("name"):
            body += _tlv(0x9F12, p["name"].encode("utf-8"))
        if p.get("spName"):
            body += _tlv(0x9F11, p["spName"].encode("utf-8"))
        return _tlv(0xE3, body)

    def encode_eim_acknowledgements(self, seq_numbers: list[int]) -> str:
        """Encode EimAcknowledgements as base64 DER."""
        return self.encode_provide_eim_package_result(
            ("eimAcknowledgements", {"seqNumbers": seq_numbers})
        )
