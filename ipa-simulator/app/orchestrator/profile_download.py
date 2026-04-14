"""
Profile Download Orchestrator — The ES9+/ES10b Authentication Dance.

Implements the full profile download flow per SGP.22 §3.1.3:

Step 1: IPA -> eUICC:  GetEuiccChallenge
Step 2: IPA -> eUICC:  GetEuiccInfo1
Step 3: IPA -> SM-DP+: InitiateAuthentication (with challenge + info1)
Step 4: IPA -> eUICC:  AuthenticateServer (with SM-DP+ signed data)
Step 5: IPA -> SM-DP+: AuthenticateClient (with eUICC signed response)
Step 6: IPA -> eUICC:  PrepareDownload (with SM-DP+ download preparation)
Step 7: IPA -> SM-DP+: GetBoundProfilePackage (with eUICC OTPK)
Step 8: IPA -> eUICC:  LoadBoundProfilePackage (install the profile)

The IPA acts as a relay/orchestrator — it doesn't process crypto,
it just passes signed data between SM-DP+ and eUICC.
"""

import base64
import structlog
from dataclasses import dataclass, field
from enum import Enum

from ..clients.euicc_client import EuiccClient
from ..clients.smdp_client import SmdpClient

logger = structlog.get_logger()


class DownloadState(str, Enum):
    IDLE = "idle"
    CHALLENGE_OBTAINED = "challenge_obtained"
    AUTH_INITIATED = "auth_initiated"
    SERVER_AUTHENTICATED = "server_authenticated"
    CLIENT_AUTHENTICATED = "client_authenticated"
    DOWNLOAD_PREPARED = "download_prepared"
    BPP_OBTAINED = "bpp_obtained"
    PROFILE_INSTALLED = "profile_installed"
    FAILED = "failed"
    CANCELLED = "cancelled"


@dataclass
class DownloadSession:
    """Tracks the state of a profile download session."""
    eid: str
    smdp_address: str
    state: DownloadState = DownloadState.IDLE
    transaction_id: str = ""
    euicc_challenge: str = ""
    euicc_info1: dict = field(default_factory=dict)
    server_signed1: dict = field(default_factory=dict)
    server_signature1: str = ""
    server_certificate: str = ""
    ci_pkid: str = ""
    euicc_signed1: dict = field(default_factory=dict)
    euicc_signature1: str = ""
    euicc_certificate: str = ""
    eum_certificate: str = ""
    smdp_signed2: dict = field(default_factory=dict)
    smdp_signature2: str = ""
    euicc_signed2: dict = field(default_factory=dict)
    euicc_signature2: str = ""
    bpp: dict = field(default_factory=dict)
    result: dict = field(default_factory=dict)
    error: str = ""
    # Matching/activation for targeted downloads
    matching_id: str = ""
    activation_code: str = ""
    # Step-by-step log for visualization
    steps: list[dict] = field(default_factory=list)


class ProfileDownloadOrchestrator:
    """
    Orchestrates the full profile download flow.

    This is the core IPA logic — it drives the mutual authentication
    between SM-DP+ and eUICC, then downloads and installs the profile.
    """

    def __init__(self, euicc: EuiccClient, smdp: SmdpClient):
        self.euicc = euicc
        self.smdp = smdp
        self.sessions: dict[str, DownloadSession] = {}

    async def start_download(
        self,
        eid: str,
        smdp_address: str,
        matching_id: str = "",
        activation_code: str = "",
    ) -> DownloadSession:
        """
        Execute the full profile download flow.

        This runs all 8 steps sequentially, stopping on any error.
        Each step is logged for visualization in the frontend.
        """
        session = DownloadSession(
            eid=eid,
            smdp_address=smdp_address,
            matching_id=matching_id,
            activation_code=activation_code,
        )
        self.sessions[eid] = session

        try:
            await self._step1_get_challenge(session)
            await self._step2_get_euicc_info1(session)
            await self._step3_initiate_auth(session)
            await self._step4_authenticate_server(session)
            await self._step5_authenticate_client(session)
            await self._step6_prepare_download(session)
            await self._step7_get_bpp(session)
            await self._step8_load_bpp(session)

            session.state = DownloadState.PROFILE_INSTALLED
            self._log_step(session, "complete", "Profile download completed successfully")

        except Exception as e:
            session.state = DownloadState.FAILED
            session.error = str(e)
            self._log_step(session, "error", f"Download failed: {e}")
            logger.error("download_failed", eid=eid, error=str(e))

        return session

    # ------------------------------------------------------------------
    # Step 1: GetEuiccChallenge
    # ------------------------------------------------------------------

    async def _step1_get_challenge(self, session: DownloadSession):
        self._log_step(session, "step1", "IPA -> eUICC: GetEuiccChallenge")

        result = await self.euicc.get_euicc_challenge(session.eid)
        session.euicc_challenge = result["euiccChallenge"]
        session.state = DownloadState.CHALLENGE_OBTAINED

        logger.info("step1_challenge", eid=session.eid, challenge=session.euicc_challenge[:16])

    # ------------------------------------------------------------------
    # Step 2: GetEuiccInfo1
    # ------------------------------------------------------------------

    async def _step2_get_euicc_info1(self, session: DownloadSession):
        self._log_step(session, "step2", "IPA -> eUICC: GetEuiccInfo1")

        result = await self.euicc.get_euicc_info1(session.eid)
        session.euicc_info1 = result

        logger.info("step2_info1", eid=session.eid, svn=result.get("svn"))

    # ------------------------------------------------------------------
    # Step 3: InitiateAuthentication (to SM-DP+)
    # ------------------------------------------------------------------

    async def _step3_initiate_auth(self, session: DownloadSession):
        self._log_step(session, "step3", "IPA -> SM-DP+: InitiateAuthentication")

        result = await self.smdp.initiate_authentication(
            euicc_challenge=session.euicc_challenge,
            euicc_info1=session.euicc_info1,
            smdp_address=session.smdp_address,
        )

        if "error" in result:
            raise RuntimeError(f"InitiateAuthentication failed: {result['error']}")

        session.transaction_id = result.get("transactionId", "")
        session.server_signed1 = result.get("serverSigned1", {})
        session.server_signature1 = result.get("serverSignature1", "")
        session.server_certificate = result.get("serverCertificate", "")
        session.ci_pkid = result.get("euiccCiPKIdToBeUsed", "")

        session.state = DownloadState.AUTH_INITIATED

        logger.info(
            "step3_auth_initiated",
            eid=session.eid,
            transaction_id=session.transaction_id,
        )

    # ------------------------------------------------------------------
    # Step 4: AuthenticateServer (relay SM-DP+ data to eUICC)
    # ------------------------------------------------------------------

    async def _step4_authenticate_server(self, session: DownloadSession):
        self._log_step(session, "step4", "IPA -> eUICC: AuthenticateServer")

        result = await self.euicc.authenticate_server(
            eid=session.eid,
            server_signed1=session.server_signed1,
            server_signature1=session.server_signature1,
            ci_pkid=session.ci_pkid,
            server_certificate_b64=session.server_certificate,
        )

        if "authenticateResponseError" in result:
            error = result["authenticateResponseError"]
            raise RuntimeError(
                f"AuthenticateServer failed: error code {error.get('authenticateErrorCode')}"
            )

        ok = result["authenticateResponseOk"]
        session.euicc_signed1 = ok["euiccSigned1"]
        session.euicc_signature1 = ok["euiccSignature1"]
        session.euicc_certificate = ok["euiccCertificate"]
        session.eum_certificate = ok.get("eumCertificate", "")

        session.state = DownloadState.SERVER_AUTHENTICATED

        logger.info("step4_server_authenticated", eid=session.eid)

    # ------------------------------------------------------------------
    # Step 5: AuthenticateClient (relay eUICC proof to SM-DP+)
    # ------------------------------------------------------------------

    async def _step5_authenticate_client(self, session: DownloadSession):
        self._log_step(session, "step5", "IPA -> SM-DP+: AuthenticateClient")

        result = await self.smdp.authenticate_client(
            transaction_id=session.transaction_id,
            euicc_signed1=session.euicc_signed1,
            euicc_signature1=session.euicc_signature1,
            euicc_certificate=session.euicc_certificate,
            eum_certificate=session.eum_certificate,
        )

        if "error" in result:
            raise RuntimeError(f"AuthenticateClient failed: {result['error']}")

        session.smdp_signed2 = result.get("smdpSigned2", {})
        session.smdp_signature2 = result.get("smdpSignature2", "")

        session.state = DownloadState.CLIENT_AUTHENTICATED

        logger.info("step5_client_authenticated", eid=session.eid)

    # ------------------------------------------------------------------
    # Step 6: PrepareDownload (relay SM-DP+ data to eUICC)
    # ------------------------------------------------------------------

    async def _step6_prepare_download(self, session: DownloadSession):
        self._log_step(session, "step6", "IPA -> eUICC: PrepareDownload")

        result = await self.euicc.prepare_download(
            eid=session.eid,
            smdp_signed2=session.smdp_signed2,
            smdp_signature2=session.smdp_signature2,
        )

        if "downloadResponseError" in result:
            error = result["downloadResponseError"]
            raise RuntimeError(
                f"PrepareDownload failed: error code {error.get('downloadErrorCode')}"
            )

        ok = result["downloadResponseOk"]
        session.euicc_signed2 = ok["euiccSigned2"]
        session.euicc_signature2 = ok["euiccSignature2"]

        session.state = DownloadState.DOWNLOAD_PREPARED

        logger.info("step6_download_prepared", eid=session.eid)

    # ------------------------------------------------------------------
    # Step 7: GetBoundProfilePackage (from SM-DP+)
    # ------------------------------------------------------------------

    async def _step7_get_bpp(self, session: DownloadSession):
        self._log_step(session, "step7", "IPA -> SM-DP+: GetBoundProfilePackage")

        result = await self.smdp.get_bound_profile_package(
            transaction_id=session.transaction_id,
            euicc_signed2=session.euicc_signed2,
            euicc_signature2=session.euicc_signature2,
        )

        if "error" in result:
            raise RuntimeError(f"GetBoundProfilePackage failed: {result['error']}")

        session.bpp = result.get("boundProfilePackage", {})
        session.state = DownloadState.BPP_OBTAINED

        logger.info("step7_bpp_obtained", eid=session.eid)

    # ------------------------------------------------------------------
    # Step 8: LoadBoundProfilePackage (install on eUICC)
    # ------------------------------------------------------------------

    async def _step8_load_bpp(self, session: DownloadSession):
        self._log_step(session, "step8", "IPA -> eUICC: LoadBoundProfilePackage")

        result = await self.euicc.load_bound_profile_package(
            eid=session.eid,
            bpp=session.bpp,
        )

        session.result = result

        pir = result.get("profileInstallationResult", {})
        pird = pir.get("profileInstallationResultData", {})
        final = pird.get("finalResult", {})

        if "errorResult" in final:
            error = final["errorResult"]
            raise RuntimeError(
                f"LoadBPP failed: command={error.get('bppCommandId')}, "
                f"reason={error.get('errorReason')}"
            )

        logger.info("step8_profile_installed", eid=session.eid)

    # ------------------------------------------------------------------
    # Cancel
    # ------------------------------------------------------------------

    async def cancel_download(self, eid: str, reason: int = 0) -> dict:
        """Cancel an active download session."""
        session = self.sessions.get(eid)
        if session is None:
            return {"error": "no_active_session"}

        if not session.transaction_id:
            session.state = DownloadState.CANCELLED
            return {"status": "cancelled_locally"}

        # Cancel on eUICC
        euicc_result = await self.euicc.cancel_session(
            eid, session.transaction_id, reason
        )

        # Forward cancellation to SM-DP+
        if "cancelSessionResponseOk" in euicc_result:
            await self.smdp.cancel_session(
                session.transaction_id,
                euicc_result["cancelSessionResponseOk"],
            )

        session.state = DownloadState.CANCELLED
        return {"status": "cancelled", "euiccResult": euicc_result}

    def get_session(self, eid: str) -> DownloadSession | None:
        return self.sessions.get(eid)

    @staticmethod
    def _log_step(session: DownloadSession, step: str, message: str):
        session.steps.append({"step": step, "message": message})
