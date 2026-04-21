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


class Asn1Codec:
    """ASN.1 DER codec for IPA simulator ESipa messages."""

    def __init__(self):
        self.schema = _compile_schema()

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
