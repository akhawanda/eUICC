"""
ESipa Handler — eIM Package Polling and Relay.

Implements the IPA's ESipa responsibilities per SGP.32:
1. Poll eIM for packages (getEimPackage)
2. Determine package type (PSMO/eCO, scan, profile download trigger)
3. Relay to eUICC via ES10 (ESep)
4. Return results to eIM (provideEimPackageResult)

This is the bridge that connects the ESipa interface (eIM side)
to the ES10b interface (eUICC side), implementing the ESep relay.
"""

import asyncio
import structlog
from dataclasses import dataclass, field

from ..clients.euicc_client import EuiccClient
from ..clients.eim_client import EimClient
from .profile_download import ProfileDownloadOrchestrator

logger = structlog.get_logger()


@dataclass
class EsipaSession:
    """Tracks an ESipa polling session for a device."""
    eid: str
    eim_id: str
    eim_fqdn: str
    polling: bool = False
    poll_interval: int = 30  # seconds
    last_result: dict = field(default_factory=dict)
    error_count: int = 0
    operations_processed: int = 0


class EsipaHandler:
    """
    Handles ESipa communication between eIM and eUICC via IPA.

    The IPA polls the eIM for packages, determines what type of
    operation is requested, executes it on the eUICC, and returns
    the result to the eIM.
    """

    def __init__(
        self,
        euicc: EuiccClient,
        eim: EimClient,
        download_orchestrator: ProfileDownloadOrchestrator,
    ):
        self.euicc = euicc
        self.eim = eim
        self.download = download_orchestrator
        self.sessions: dict[str, EsipaSession] = {}
        self._poll_tasks: dict[str, asyncio.Task] = {}

    async def register_device(
        self, eid: str, eim_id: str, eim_fqdn: str, poll_interval: int = 30
    ) -> EsipaSession:
        """Register a device for ESipa polling."""
        session = EsipaSession(
            eid=eid,
            eim_id=eim_id,
            eim_fqdn=eim_fqdn,
            poll_interval=poll_interval,
        )
        self.sessions[eid] = session
        return session

    async def start_polling(self, eid: str) -> dict:
        """Start polling eIM for packages for a specific device."""
        session = self.sessions.get(eid)
        if session is None:
            return {"error": "device_not_registered"}

        if session.polling:
            return {"status": "already_polling"}

        session.polling = True
        task = asyncio.create_task(self._poll_loop(session))
        self._poll_tasks[eid] = task

        logger.info("polling_started", eid=eid, eim_id=session.eim_id)
        return {"status": "polling_started", "interval": session.poll_interval}

    async def stop_polling(self, eid: str) -> dict:
        """Stop polling for a device."""
        session = self.sessions.get(eid)
        if session is None:
            return {"error": "device_not_registered"}

        session.polling = False
        task = self._poll_tasks.pop(eid, None)
        if task:
            task.cancel()

        return {"status": "polling_stopped"}

    async def poll_once(self, eid: str) -> dict:
        """Execute a single poll cycle (for manual triggering)."""
        session = self.sessions.get(eid)
        if session is None:
            return {"error": "device_not_registered"}

        return await self._process_poll(session)

    async def _poll_loop(self, session: EsipaSession):
        """Background polling loop."""
        while session.polling:
            try:
                await self._process_poll(session)
                session.error_count = 0
            except asyncio.CancelledError:
                break
            except Exception as e:
                session.error_count += 1
                logger.error(
                    "poll_error",
                    eid=session.eid,
                    error=str(e),
                    error_count=session.error_count,
                )
                if session.error_count > 10:
                    session.polling = False
                    break

            await asyncio.sleep(session.poll_interval)

    async def _process_poll(self, session: EsipaSession) -> dict:
        """
        Execute one poll cycle:
        1. Call getEimPackage on eIM
        2. Process the response based on type
        3. Return result to eIM
        """
        # Step 1: Get package from eIM
        package = await self.eim.get_eim_package(session.eid, session.eim_id)

        # Check for no-package or error
        if "eimPackageError" in package:
            error_code = package["eimPackageError"]
            if error_code == 1:  # noEimPackageAvailable
                return {"status": "no_package"}
            return {"status": "error", "code": error_code}

        # Step 2: Determine package type and process
        result = await self._dispatch_package(session, package)

        # Step 3: Send result back to eIM
        if result.get("sendResult", True):
            result_data = result.get("resultData", "")
            if result_data:
                eim_response = await self.eim.provide_eim_package_result(
                    session.eid, result_data
                )
                result["eimResponse"] = eim_response

        session.last_result = result
        session.operations_processed += 1

        return result

    async def _dispatch_package(self, session: EsipaSession, package: dict) -> dict:
        """Route the package to the appropriate handler based on type."""

        # Type 1: euiccPackageRequest (PSMO/eCO operations)
        if "euiccPackageRequest" in package:
            return await self._handle_euicc_package(session, package["euiccPackageRequest"])

        # Type 2: ipaEuiccDataRequest (scan/data request)
        if "ipaEuiccDataRequest" in package:
            return await self._handle_data_request(session)

        # Type 3: profileDownloadTriggerRequest
        if "profileDownloadTriggerRequest" in package:
            return await self._handle_download_trigger(
                session, package["profileDownloadTriggerRequest"]
            )

        return {"status": "unknown_package_type", "sendResult": False}

    async def _handle_euicc_package(self, session: EsipaSession, package: dict) -> dict:
        """
        Handle euiccPackageRequest — relay PSMO/eCO to eUICC via ES10.

        This is the ESep relay: eIM -> IPA (ESipa) -> eUICC (ES10b).
        """
        logger.info(
            "esep_relay",
            eid=session.eid,
            psmo_count=len(package.get("psmoList", [])),
            eco_count=len(package.get("ecoList", [])),
        )

        # Relay to eUICC
        result = await self.euicc.load_euicc_package(session.eid, package)

        return {
            "status": "euicc_package_processed",
            "result": result,
            "resultData": "",  # TODO: DER-encode BF50 result
            "sendResult": True,
        }

    async def _handle_data_request(self, session: EsipaSession) -> dict:
        """
        Handle ipaEuiccDataRequest — collect eUICC data for eIM.

        The eIM wants to scan this eUICC's current state:
        profiles, eIM configs, certificates.
        """
        logger.info("euicc_data_requested", eid=session.eid)

        # Collect data from eUICC
        profiles = await self.euicc.get_profiles_info(session.eid)
        eim_config = await self.euicc.get_eim_config(session.eid)
        eid_response = await self.euicc.get_eid(session.eid)

        return {
            "status": "data_collected",
            "data": {
                "eid": eid_response,
                "profiles": profiles,
                "eimConfig": eim_config,
            },
            "resultData": "",  # TODO: DER-encode BF52 response
            "sendResult": True,
        }

    async def _handle_download_trigger(self, session: EsipaSession, trigger: dict) -> dict:
        """
        Handle profileDownloadTriggerRequest — initiate profile download.

        The eIM triggers the IPA to download a profile from SM-DP+.
        This kicks off the full ES9+/ES10b authentication dance.
        """
        smdp_address = trigger.get("smdpAddress", "")
        activation_code = trigger.get("activationCode", "")
        matching_id = trigger.get("matchingId", "")

        if not smdp_address:
            # Get default from eUICC configuration
            addresses = await self.euicc.get_configured_addresses(session.eid)
            smdp_address = addresses.get("defaultDpAddress", "")

        logger.info(
            "download_triggered",
            eid=session.eid,
            smdp_address=smdp_address,
        )

        # Execute the full download flow
        download_session = await self.download.start_download(
            eid=session.eid,
            smdp_address=smdp_address,
            matching_id=matching_id,
            activation_code=activation_code,
        )

        return {
            "status": download_session.state.value,
            "steps": download_session.steps,
            "result": download_session.result,
            "error": download_session.error,
            "resultData": "",  # TODO: DER-encode BF54 result
            "sendResult": True,
        }
