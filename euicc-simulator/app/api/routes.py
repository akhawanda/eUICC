"""
eUICC Simulator API Routes.

Exposes the eUICC's ES10 interfaces via REST API so the IPA simulator
can communicate without needing a physical APDU channel.

Two API families:
1. /api/es10/ — Direct ES10 function calls (JSON request/response)
2. /api/apdu/ — Raw APDU interface (hex-encoded STORE DATA commands)
3. /api/management/ — eUICC instance management (create, list, delete)
"""

import base64
from fastapi import APIRouter, HTTPException, Body
from pydantic import BaseModel, Field

from ..services.euicc_manager import EuiccManager

router = APIRouter()

# Global manager instance (initialized in main.py)
manager: EuiccManager | None = None


def set_manager(mgr: EuiccManager):
    global manager
    manager = mgr


def _get_instance(eid: str):
    inst = manager.get_euicc(eid)
    if inst is None:
        raise HTTPException(status_code=404, detail=f"eUICC not found: {eid}")
    return inst


# =====================================================================
# Management API
# =====================================================================


class CreateEuiccRequest(BaseModel):
    eid: str = Field(..., min_length=32, max_length=32)
    defaultSmdpAddress: str = ""
    eimId: str | None = None
    eimFqdn: str | None = None
    preloadedProfiles: list[dict] | None = None


class CreateTestEuiccsRequest(BaseModel):
    smdpAddress: str = ""
    eimFqdn: str = ""


@router.post("/api/management/euicc")
async def create_euicc(req: CreateEuiccRequest):
    inst = manager.create_euicc(
        eid=req.eid,
        default_smdp_address=req.defaultSmdpAddress,
        eim_id=req.eimId,
        eim_fqdn=req.eimFqdn,
        preloaded_profiles=req.preloadedProfiles,
    )
    return {
        "status": "created",
        "eid": req.eid,
        "profiles": len(inst.euicc.profiles),
        "eimAssociations": len(inst.euicc.eim_associations),
    }


@router.post("/api/management/euicc/test-data")
async def create_test_euiccs(req: CreateTestEuiccsRequest):
    manager.create_test_euiccs(req.smdpAddress, req.eimFqdn)
    return {"status": "ok", "count": len(manager.instances)}


@router.get("/api/management/euicc")
async def list_euiccs():
    return {"euiccs": manager.list_euiccs()}


@router.get("/api/management/euicc/{eid}")
async def get_euicc(eid: str):
    inst = _get_instance(eid)
    e = inst.euicc
    return {
        "eid": e.eid,
        "svn": ".".join(str(v) for v in e.svn),
        "firmwareVersion": ".".join(str(v) for v in e.firmware_version),
        "freeNvm": e.free_nvm,
        "totalNvm": e.total_nvm,
        "maxProfiles": e.max_profiles,
        "defaultSmdpAddress": e.default_smdp_address,
        "rootDsAddress": e.root_ds_address,
        "ipaMode": e.ipa_mode,
        "profiles": [
            {
                "iccid": p.iccid_string(),
                "state": p.state.value,
                "profileName": p.profile_name,
                "serviceProviderName": p.service_provider_name,
                "profileClass": p.profile_class.value,
                "nickname": p.profile_nickname,
            }
            for p in e.profiles
        ],
        "eimAssociations": [
            {
                "eimId": a.eim_id,
                "eimFqdn": a.eim_fqdn,
                "counterValue": a.counter_value,
                "associationToken": a.association_token,
            }
            for a in e.eim_associations
        ],
        "notifications": len(e.notifications),
        "hasActiveSession": e.active_session is not None,
    }


@router.delete("/api/management/euicc/{eid}")
async def delete_euicc(eid: str):
    if manager.delete_euicc(eid):
        return {"status": "deleted", "eid": eid}
    raise HTTPException(status_code=404, detail=f"eUICC not found: {eid}")


# =====================================================================
# ES10a — Configuration Interface
# =====================================================================


@router.get("/api/es10/{eid}/configured-addresses")
async def get_configured_addresses(eid: str):
    inst = _get_instance(eid)
    return inst.es10a.get_euicc_configured_addresses()


class SetDpAddressRequest(BaseModel):
    defaultDpAddress: str


@router.post("/api/es10/{eid}/configured-addresses")
async def set_default_dp_address(eid: str, req: SetDpAddressRequest):
    inst = _get_instance(eid)
    return inst.es10a.set_default_dp_address(req.defaultDpAddress)


# =====================================================================
# ES10b — Profile Download & Authentication
# =====================================================================


@router.get("/api/es10/{eid}/euicc-info1")
async def get_euicc_info1(eid: str):
    inst = _get_instance(eid)
    result = inst.es10b.get_euicc_info1()
    # Convert bytes to hex for JSON
    return _bytes_to_hex(result)


@router.get("/api/es10/{eid}/euicc-info2")
async def get_euicc_info2(eid: str):
    inst = _get_instance(eid)
    result = inst.es10b.get_euicc_info2()
    return _bytes_to_hex(result)


@router.post("/api/es10/{eid}/euicc-challenge")
async def get_euicc_challenge(eid: str):
    inst = _get_instance(eid)
    result = inst.es10b.get_euicc_challenge()
    return _bytes_to_hex(result)


class AuthenticateServerRequest(BaseModel):
    serverSigned1: dict
    serverSignature1: str  # hex
    euiccCiPKIdToBeUsed: str  # hex
    serverCertificate: str  # base64 DER
    # ctxParams1 is a CHOICE — comes either as dict {"ctxParamsForCommonAuthentication": {...}}
    # or as a 2-element list ["ctxParamsForCommonAuthentication", {...}] (JSON form of a Python tuple).
    ctxParams1: dict | list | None = None
    serverSigned1Raw: str | None = None  # base64 of the original DER SS1 from SM-DP+


@router.post("/api/es10/{eid}/authenticate-server")
async def authenticate_server(eid: str, req: AuthenticateServerRequest):
    inst = _get_instance(eid)

    # Decode hex/base64 fields
    server_signed1 = _hex_to_bytes_dict(req.serverSigned1)
    server_sig = bytes.fromhex(req.serverSignature1)
    ci_pkid = bytes.fromhex(req.euiccCiPKIdToBeUsed)
    server_cert = base64.b64decode(req.serverCertificate)
    ss1_raw = base64.b64decode(req.serverSigned1Raw) if req.serverSigned1Raw else None

    result = inst.es10b.authenticate_server(
        server_signed1=server_signed1,
        server_signature1=server_sig,
        euicc_ci_pkid=ci_pkid,
        server_certificate_der=server_cert,
        ctx_params1=req.ctxParams1,
        server_signed1_raw=ss1_raw,
    )
    return _bytes_to_hex(result)


class PrepareDownloadRequest(BaseModel):
    smdpSigned2: dict
    smdpSignature2: str  # hex
    hashCc: str | None = None  # hex
    smdpCertificate: str | None = None  # base64 DER
    smdpSigned2Raw: str | None = None  # base64 of original DER bytes


@router.post("/api/es10/{eid}/prepare-download")
async def prepare_download(eid: str, req: PrepareDownloadRequest):
    inst = _get_instance(eid)

    smdp_signed2 = _hex_to_bytes_dict(req.smdpSigned2)
    smdp_sig = bytes.fromhex(req.smdpSignature2)
    hash_cc = bytes.fromhex(req.hashCc) if req.hashCc else None
    smdp_cert = base64.b64decode(req.smdpCertificate) if req.smdpCertificate else None
    smdp_signed2_raw = base64.b64decode(req.smdpSigned2Raw) if req.smdpSigned2Raw else None

    result = inst.es10b.prepare_download(
        smdp_signed2=smdp_signed2,
        smdp_signature2=smdp_sig,
        hash_cc=hash_cc,
        smdp_certificate_der=smdp_cert,
        smdp_signed2_raw=smdp_signed2_raw,
    )
    return _bytes_to_hex(result)


class LoadBppRequest(BaseModel):
    # The SM-DP+ returns boundProfilePackage as a single base64-encoded DER
    # blob (BF36); accept either that string form or a pre-parsed dict for
    # tests. We decode + walk the DER on entry when string is provided.
    boundProfilePackage: dict | str


def _walk_bpp_der(der: bytes) -> dict:
    """Minimal BPP TLV walk into {initialiseSecureChannelRequest,
    firstSequenceOf87, sequenceOf88, secondSequenceOf87, sequenceOf86}.
    Per SGP.22 §5.5.4 the BPP outer tag is BF36; inside there are tagged
    SEQUENCEs that carry the SCP03t-encrypted profile elements."""
    out: dict = {
        "initialiseSecureChannelRequest": {},
        "firstSequenceOf87": b"",
        "sequenceOf88": b"",
        "secondSequenceOf87": b"",
        "sequenceOf86": b"",
    }
    if not der or der[:2] != b"\xBF\x36":
        return out
    pos = 2
    body_len = der[pos]; pos += 1
    if body_len & 0x80:
        n = body_len & 0x7F
        body_len = int.from_bytes(der[pos:pos + n], "big")
        pos += n
    end = pos + body_len
    while pos < end:
        tag = der[pos]; pos += 1
        if pos >= end:
            break
        ln = der[pos]; pos += 1
        if ln & 0x80:
            n = ln & 0x7F
            ln = int.from_bytes(der[pos:pos + n], "big")
            pos += n
        v = der[pos:pos + ln]; pos += ln
        # Tags per SGP.22 BoundProfilePackage:
        #   30 = initialiseSecureChannelRequest (SEQUENCE)
        #   A0 = firstSequenceOf87 wrapper
        #   A1 = sequenceOf88 wrapper
        #   A2 = secondSequenceOf87 wrapper
        #   A3 = sequenceOf86 wrapper
        if tag == 0x30:
            out["initialiseSecureChannelRequest"] = {"raw": v}
        elif tag == 0xA0:
            out["firstSequenceOf87"] = v
        elif tag == 0xA1:
            out["sequenceOf88"] = v
        elif tag == 0xA2:
            out["secondSequenceOf87"] = v
        elif tag == 0xA3:
            out["sequenceOf86"] = v
    return out


@router.post("/api/es10/{eid}/load-bpp")
async def load_bound_profile_package(eid: str, req: LoadBppRequest):
    inst = _get_instance(eid)
    raw_bpp = req.boundProfilePackage
    if isinstance(raw_bpp, str):
        try:
            der = base64.b64decode(raw_bpp)
        except Exception:
            der = bytes.fromhex(raw_bpp)
        bpp = _walk_bpp_der(der)
    else:
        bpp = _hex_to_bytes_dict(raw_bpp)
    result = inst.es10b.load_bound_profile_package(bpp)
    return _bytes_to_hex(result)


class CancelSessionRequest(BaseModel):
    transactionId: str  # hex
    reason: int


@router.post("/api/es10/{eid}/cancel-session")
async def cancel_session(eid: str, req: CancelSessionRequest):
    inst = _get_instance(eid)
    result = inst.es10b.cancel_session(
        transaction_id=bytes.fromhex(req.transactionId),
        reason=req.reason,
    )
    return _bytes_to_hex(result)


# =====================================================================
# ES10c — Local Profile Management
# =====================================================================


@router.get("/api/es10/{eid}/profiles")
async def get_profiles_info(eid: str):
    inst = _get_instance(eid)
    return inst.es10c.get_profiles_info()


class ProfileActionRequest(BaseModel):
    iccid: str | None = None  # hex
    isdpAid: str | None = None  # hex
    refreshFlag: bool = True


@router.post("/api/es10/{eid}/profiles/enable")
async def enable_profile(eid: str, req: ProfileActionRequest):
    inst = _get_instance(eid)
    iccid = bytes.fromhex(req.iccid) if req.iccid else None
    aid = bytes.fromhex(req.isdpAid) if req.isdpAid else None
    return inst.es10c.enable_profile(iccid=iccid, aid=aid, refresh=req.refreshFlag)


@router.post("/api/es10/{eid}/profiles/disable")
async def disable_profile(eid: str, req: ProfileActionRequest):
    inst = _get_instance(eid)
    iccid = bytes.fromhex(req.iccid) if req.iccid else None
    aid = bytes.fromhex(req.isdpAid) if req.isdpAid else None
    return inst.es10c.disable_profile(iccid=iccid, aid=aid, refresh=req.refreshFlag)


@router.post("/api/es10/{eid}/profiles/delete")
async def delete_profile(eid: str, req: ProfileActionRequest):
    inst = _get_instance(eid)
    iccid = bytes.fromhex(req.iccid) if req.iccid else None
    aid = bytes.fromhex(req.isdpAid) if req.isdpAid else None
    return inst.es10c.delete_profile(iccid=iccid, aid=aid)


@router.get("/api/es10/{eid}/eid")
async def get_eid(eid: str):
    inst = _get_instance(eid)
    result = inst.es10c.get_eid()
    return _bytes_to_hex(result)


class SetNicknameRequest(BaseModel):
    iccid: str  # hex
    profileNickname: str


@router.post("/api/es10/{eid}/nickname")
async def set_nickname(eid: str, req: SetNicknameRequest):
    inst = _get_instance(eid)
    return inst.es10c.set_nickname(
        iccid=bytes.fromhex(req.iccid),
        nickname=req.profileNickname,
    )


class MemoryResetRequest(BaseModel):
    resetOptions: str | None = None  # hex


@router.post("/api/es10/{eid}/memory-reset")
async def euicc_memory_reset(eid: str, req: MemoryResetRequest):
    inst = _get_instance(eid)
    options = bytes.fromhex(req.resetOptions) if req.resetOptions else None
    return inst.es10c.euicc_memory_reset(reset_options=options)


# =====================================================================
# ES10b IoT — eIM Configuration (SGP.32)
# =====================================================================


@router.get("/api/es10/{eid}/eim-config")
async def get_eim_config(eid: str):
    inst = _get_instance(eid)
    return inst.es10b_iot.get_eim_configuration_data()


class AddEimRequest(BaseModel):
    eimId: str
    eimFqdn: str = ""
    counterValue: int = 0
    eimSupportedProtocol: int = 0


@router.post("/api/es10/{eid}/eim/add")
async def add_eim(eid: str, req: AddEimRequest):
    inst = _get_instance(eid)
    return inst.es10b_iot.add_eim(req.model_dump())


class DeleteEimRequest(BaseModel):
    eimId: str


@router.post("/api/es10/{eid}/eim/delete")
async def delete_eim(eid: str, req: DeleteEimRequest):
    inst = _get_instance(eid)
    return inst.es10b_iot.delete_eim(req.eimId)


@router.get("/api/es10/{eid}/certs")
async def get_certs(eid: str):
    inst = _get_instance(eid)
    result = inst.es10b_iot.get_certs()
    return {
        "eumCertificate": base64.b64encode(result["eumCertificate"]).decode(),
        "euiccCertificate": base64.b64encode(result["euiccCertificate"]).decode(),
    }


# Notifications
@router.get("/api/es10/{eid}/notifications")
async def list_notifications(eid: str):
    inst = _get_instance(eid)
    return inst.es10b.list_notifications()


class LoadEuiccPackageRequest(BaseModel):
    eimId: str
    counterValue: int
    psmoList: list[dict] = []
    ecoList: list[dict] = []


@router.post("/api/es10/{eid}/euicc-package")
async def load_euicc_package(eid: str, req: LoadEuiccPackageRequest):
    inst = _get_instance(eid)
    return inst.es10b_iot.load_euicc_package(req.model_dump())


# =====================================================================
# Raw APDU Interface
# =====================================================================


class ApduRequest(BaseModel):
    apdu: str  # hex-encoded APDU


@router.post("/api/apdu/{eid}")
async def process_apdu(eid: str, req: ApduRequest):
    inst = _get_instance(eid)
    apdu_bytes = bytes.fromhex(req.apdu)
    response = inst.apdu.process_apdu(apdu_bytes)
    return {"response": response.hex(), "sw": response[-2:].hex()}


# =====================================================================
# Helpers
# =====================================================================


def _bytes_to_hex(obj):
    """Recursively convert bytes/bytearray values to hex strings for JSON
    serialization. Handles tuples (CHOICE alternatives) too."""
    if isinstance(obj, (bytes, bytearray, memoryview)):
        return bytes(obj).hex()
    elif isinstance(obj, dict):
        return {k: _bytes_to_hex(v) for k, v in obj.items()}
    elif isinstance(obj, (list, tuple)):
        return [_bytes_to_hex(v) for v in obj]
    return obj


def _hex_to_bytes_dict(obj):
    """Convert hex string values back to bytes (for known binary fields)."""
    BINARY_FIELDS = {
        "transactionId", "euiccChallenge", "serverChallenge",
        "euiccOtpk", "bppEuiccOtpk", "hashCc", "iccid",
        "isdpAid", "aid",
    }
    if isinstance(obj, dict):
        result = {}
        for k, v in obj.items():
            if k in BINARY_FIELDS and isinstance(v, str):
                result[k] = bytes.fromhex(v)
            else:
                result[k] = _hex_to_bytes_dict(v)
        return result
    elif isinstance(obj, list):
        return [_hex_to_bytes_dict(v) for v in obj]
    return obj
