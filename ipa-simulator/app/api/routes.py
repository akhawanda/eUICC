"""
IPA Simulator API Routes.

Exposes the IPA orchestrator functionality:
1. /api/ipa/download — Profile download management
2. /api/ipa/esipa — ESipa polling and relay
3. /api/ipa/devices — Device registration
4. /api/ipa/status — Session monitoring
"""

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field
from dataclasses import asdict

from ..orchestrator.profile_download import ProfileDownloadOrchestrator
from ..orchestrator.esipa_handler import EsipaHandler

router = APIRouter()

# Global instances (initialized in main.py)
download_orchestrator: ProfileDownloadOrchestrator | None = None
esipa_handler: EsipaHandler | None = None


def set_orchestrators(dl: ProfileDownloadOrchestrator, esipa: EsipaHandler):
    global download_orchestrator, esipa_handler
    download_orchestrator = dl
    esipa_handler = esipa


# =====================================================================
# Device Registration
# =====================================================================


class RegisterDeviceRequest(BaseModel):
    eid: str = Field(..., min_length=32, max_length=32)
    eimId: str
    eimFqdn: str = ""
    pollInterval: int = 30


@router.post("/api/ipa/devices")
async def register_device(req: RegisterDeviceRequest):
    session = await esipa_handler.register_device(
        eid=req.eid,
        eim_id=req.eimId,
        eim_fqdn=req.eimFqdn,
        poll_interval=req.pollInterval,
    )
    return {
        "status": "registered",
        "eid": session.eid,
        "eimId": session.eim_id,
        "pollInterval": session.poll_interval,
    }


@router.get("/api/ipa/devices")
async def list_devices():
    devices = []
    for eid, session in esipa_handler.sessions.items():
        devices.append({
            "eid": eid,
            "eimId": session.eim_id,
            "eimFqdn": session.eim_fqdn,
            "polling": session.polling,
            "operationsProcessed": session.operations_processed,
            "errorCount": session.error_count,
        })
    return {"devices": devices}


@router.get("/api/ipa/devices/{eid}")
async def get_device(eid: str):
    session = esipa_handler.sessions.get(eid)
    if session is None:
        raise HTTPException(status_code=404, detail="Device not registered")
    return {
        "eid": session.eid,
        "eimId": session.eim_id,
        "eimFqdn": session.eim_fqdn,
        "polling": session.polling,
        "pollInterval": session.poll_interval,
        "lastResult": session.last_result,
        "operationsProcessed": session.operations_processed,
        "errorCount": session.error_count,
    }


# =====================================================================
# Profile Download
# =====================================================================


class StartDownloadRequest(BaseModel):
    eid: str
    smdpAddress: str
    matchingId: str = ""
    activationCode: str = ""


@router.post("/api/ipa/download/start")
async def start_download(req: StartDownloadRequest):
    session = await download_orchestrator.start_download(
        eid=req.eid,
        smdp_address=req.smdpAddress,
        matching_id=req.matchingId,
        activation_code=req.activationCode,
    )
    return {
        "eid": session.eid,
        "state": session.state.value,
        "transactionId": session.transaction_id,
        "steps": session.steps,
        "error": session.error,
    }


class CancelDownloadRequest(BaseModel):
    eid: str
    reason: int = 0


@router.post("/api/ipa/download/cancel")
async def cancel_download(req: CancelDownloadRequest):
    result = await download_orchestrator.cancel_download(
        eid=req.eid, reason=req.reason
    )
    return result


@router.get("/api/ipa/download/{eid}")
async def get_download_session(eid: str):
    session = download_orchestrator.get_session(eid)
    if session is None:
        raise HTTPException(status_code=404, detail="No download session")
    return {
        "eid": session.eid,
        "state": session.state.value,
        "smdpAddress": session.smdp_address,
        "transactionId": session.transaction_id,
        "steps": session.steps,
        "error": session.error,
    }


# =====================================================================
# ESipa Polling
# =====================================================================


@router.post("/api/ipa/esipa/{eid}/start-polling")
async def start_polling(eid: str):
    result = await esipa_handler.start_polling(eid)
    return result


@router.post("/api/ipa/esipa/{eid}/stop-polling")
async def stop_polling(eid: str):
    result = await esipa_handler.stop_polling(eid)
    return result


@router.post("/api/ipa/esipa/{eid}/poll-once")
async def poll_once(eid: str):
    """Execute a single ESipa poll cycle (for manual testing)."""
    result = await esipa_handler.poll_once(eid)
    return result


# =====================================================================
# Status & Monitoring
# =====================================================================


@router.get("/api/ipa/status")
async def get_status():
    return {
        "registeredDevices": len(esipa_handler.sessions),
        "activePolling": sum(
            1 for s in esipa_handler.sessions.values() if s.polling
        ),
        "activeDownloads": sum(
            1
            for s in download_orchestrator.sessions.values()
            if s.state.value not in ("idle", "profile_installed", "failed", "cancelled")
        ),
        "totalDownloads": len(download_orchestrator.sessions),
    }
