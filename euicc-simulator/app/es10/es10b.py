"""
ES10b Interface Handler — Profile Download & Authentication.

Implements the eUICC side of the ES10b interface per SGP.22 §5.7:
1. GetEuiccInfo1 — Return CI PKI info
2. GetEuiccInfo2 — Return full eUICC capabilities
3. GetEuiccChallenge — Generate authentication challenge
4. AuthenticateServer — Verify SM-DP+ and generate eUICC response
5. PrepareDownload — Prepare for BPP, derive session keys
6. LoadBoundProfilePackage — Decrypt and install profile
7. CancelSession — Abort active download
"""

import os
import hashlib
import structlog
from cryptography import x509
from cryptography.hazmat.primitives.asymmetric.ec import EllipticCurvePublicKey

from ..models.euicc import (
    EuiccState,
    DownloadSession,
    ProfileSlot,
    ProfileState,
    ProfileClass,
)
from ..crypto.certificates import CertificateInfrastructure
from ..crypto.ecdsa_engine import EcdsaEngine, SessionKeys
from ..crypto.scp03t import Scp03tProcessor

logger = structlog.get_logger()


class Es10bHandler:
    """
    Handles ES10b commands from the IPA.

    Each method simulates what a real eUICC's ISD-R would do when
    receiving the corresponding STORE DATA APDU.
    """

    def __init__(self, euicc: EuiccState, pki: CertificateInfrastructure):
        self.euicc = euicc
        self.pki = pki
        self.ecdsa = EcdsaEngine()

    # ------------------------------------------------------------------
    # GetEuiccInfo1 (BF20)
    # ------------------------------------------------------------------

    def get_euicc_info1(self) -> dict:
        """
        Return basic eUICC info for initial authentication.

        Contains:
        - SVN (SGP.22 version)
        - CI PKI IDs for verification and signing

        This is sent BEFORE mutual authentication so it must not
        contain sensitive info.
        """
        ci_pki_id = self.pki.get_ci_pki_id()

        return {
            "svn": self.euicc.version_to_bytes(self.euicc.svn),
            "euiccCiPKIdListForVerification": [ci_pki_id],
            "euiccCiPKIdListForSigning": [ci_pki_id],
        }

    # ------------------------------------------------------------------
    # GetEuiccInfo2 (BF22)
    # ------------------------------------------------------------------

    def get_euicc_info2(self) -> dict:
        """
        Return full eUICC capabilities (sent after authentication).

        Includes memory, firmware version, capabilities, and IoT extensions.
        """
        ci_pki_id = self.pki.get_ci_pki_id()

        info2 = {
            "profileVersion": self.euicc.version_to_bytes(self.euicc.profile_version),
            "svn": self.euicc.version_to_bytes(self.euicc.svn),
            "euiccFirmwareVer": self.euicc.version_to_bytes(
                self.euicc.firmware_version
            ),
            "extCardResource": self.euicc.ext_card_resource_bytes(),
            "uiccCapability": self.euicc.uicc_capability,
            "rspCapability": self.euicc.rsp_capability,
            "euiccCiPKIdListForVerification": [ci_pki_id],
            "euiccCiPKIdListForSigning": [ci_pki_id],
            "platformLabel": self.euicc.platform_label,
            # SGP.32 IoT extensions
            "ipaMode": self.euicc.ipa_mode,
            "iotSpecificInfo": {
                "iotVersion": self.euicc.version_to_bytes(self.euicc.iot_version),
            },
        }
        return info2

    # ------------------------------------------------------------------
    # GetEuiccChallenge (BF2E)
    # ------------------------------------------------------------------

    def get_euicc_challenge(self) -> dict:
        """
        Generate a 16-byte random challenge for server authentication.

        The challenge is stored in the session state and must be
        presented back by the SM-DP+ in AuthenticateServer.
        """
        challenge = self.ecdsa.generate_challenge()

        # Store for later verification
        self.euicc.active_session = DownloadSession(
            transaction_id=b"",  # Will be set by AuthenticateServer
            server_address="",
            euicc_challenge=challenge,
        )

        logger.info(
            "euicc_challenge_generated",
            eid=self.euicc.eid,
            challenge_hex=challenge.hex(),
        )

        return {"euiccChallenge": challenge}

    # ------------------------------------------------------------------
    # AuthenticateServer (BF38)
    # ------------------------------------------------------------------

    def authenticate_server(
        self,
        server_signed1: dict,
        server_signature1: bytes,
        euicc_ci_pkid: bytes,
        server_certificate_der: bytes,
        ctx_params1: dict | None = None,
    ) -> dict:
        """
        Verify SM-DP+ server authentication and respond with eUICC proof.

        Steps per SGP.22 §5.7.3:
        1. Verify the CI PKI ID is supported
        2. Verify the server certificate chain
        3. Verify server_signature1 over server_signed1
        4. Verify the euiccChallenge matches
        5. Generate eUICC response (euiccSigned1 + euiccSignature1)
        """
        session = self.euicc.active_session
        if session is None:
            return self._auth_error(b"\x00" * 16, 8)  # invalidTransactionId

        # Step 1: Verify CI PKI ID
        ci_pki_id = self.pki.get_ci_pki_id()
        if euicc_ci_pkid != ci_pki_id:
            logger.warning("unsupported_ci_pkid", received=euicc_ci_pkid.hex())
            return self._auth_error(
                server_signed1.get("transactionId", b"\x00" * 16), 3
            )

        # Step 2: Verify server certificate
        try:
            server_cert = x509.load_der_x509_certificate(server_certificate_der)
            # In production, full chain validation against CI root
            # For simulator, verify it's a valid X.509 cert
            server_public_key = server_cert.public_key()
        except Exception as e:
            logger.error("invalid_server_certificate", error=str(e))
            return self._auth_error(
                server_signed1.get("transactionId", b"\x00" * 16), 6
            )

        # Step 3: Verify server signature
        # server_signed1 is the TBS (to-be-signed) data
        tbs_data = self._encode_server_signed1(server_signed1)
        if not self.ecdsa.verify(server_public_key, server_signature1, tbs_data):
            logger.warning("invalid_server_signature")
            return self._auth_error(
                server_signed1.get("transactionId", b"\x00" * 16), 2
            )

        # Step 4: Verify euiccChallenge matches
        received_challenge = server_signed1.get("euiccChallenge", b"")
        if received_challenge != session.euicc_challenge:
            logger.warning(
                "euicc_challenge_mismatch",
                expected=session.euicc_challenge.hex(),
                received=received_challenge.hex(),
            )
            return self._auth_error(
                server_signed1.get("transactionId", b"\x00" * 16), 2
            )

        # Step 5: Update session state
        transaction_id = server_signed1["transactionId"]
        server_address = server_signed1["serverAddress"]
        server_challenge = server_signed1["serverChallenge"]

        session.transaction_id = transaction_id
        session.server_address = server_address
        session.server_challenge = server_challenge
        session.authenticated = True

        # Step 6: Build eUICC response
        euicc_signed1 = {
            "transactionId": transaction_id,
            "serverAddress": server_address,
            "serverChallenge": server_challenge,
            "euiccInfo2": self.get_euicc_info2(),
        }

        # Sign with eUICC private key
        tbs_euicc = self._encode_euicc_signed1(euicc_signed1)
        euicc_signature1 = self.ecdsa.sign(self.pki.euicc.private_key, tbs_euicc)

        logger.info(
            "server_authenticated",
            eid=self.euicc.eid,
            server_address=server_address,
            transaction_id=transaction_id.hex(),
        )

        return {
            "authenticateResponseOk": {
                "euiccSigned1": euicc_signed1,
                "euiccSignature1": euicc_signature1,
                "euiccCertificate": self.pki.get_euicc_cert_der(),
                "eumCertificate": self.pki.get_eum_cert_der(),
            }
        }

    # ------------------------------------------------------------------
    # PrepareDownload (BF21)
    # ------------------------------------------------------------------

    def prepare_download(
        self,
        smdp_signed2: dict,
        smdp_signature2: bytes,
        hash_cc: bytes | None = None,
        smdp_certificate_der: bytes | None = None,
    ) -> dict:
        """
        Prepare for profile download — generate OTPK and derive session keys.

        Steps per SGP.22 §5.7.5:
        1. Verify the transaction ID matches active session
        2. Verify SM-DP+ signature over smdpSigned2
        3. Generate eUICC OTPK (one-time key pair)
        4. Derive SCP03t session keys via ECDH
        5. Return euiccSigned2 + euiccSignature2
        """
        session = self.euicc.active_session
        if session is None or not session.authenticated:
            return self._download_error(b"\x00" * 16, 127)  # undefinedError

        transaction_id = smdp_signed2.get("transactionId", b"")
        if transaction_id != session.transaction_id:
            return self._download_error(transaction_id, 127)

        # Generate eUICC one-time key pair
        otpk_private, otpk_public = self.ecdsa.generate_otpk()
        session.euicc_otpk_private = otpk_private
        session.euicc_otpk_public = otpk_public

        # Get SM-DP+ OTPK from smdpSigned2
        smdp_otpk = smdp_signed2.get("bppEuiccOtpk", b"")
        if smdp_otpk:
            session.smdp_otpk_public = smdp_otpk
            # Derive SCP03t session keys
            session.session_keys = self.ecdsa.derive_session_keys(
                otpk_private, smdp_otpk, transaction_id
            )

        session.prepared = True

        # Build response
        euicc_signed2 = {
            "transactionId": transaction_id,
            "euiccOtpk": otpk_public,
            "hashCc": hash_cc,
        }

        tbs_data = self._encode_euicc_signed2(euicc_signed2)
        euicc_signature2 = self.ecdsa.sign(self.pki.euicc.private_key, tbs_data)

        logger.info(
            "download_prepared",
            eid=self.euicc.eid,
            transaction_id=transaction_id.hex(),
            has_session_keys=session.session_keys is not None,
        )

        return {
            "downloadResponseOk": {
                "euiccSigned2": euicc_signed2,
                "euiccSignature2": euicc_signature2,
            }
        }

    # ------------------------------------------------------------------
    # LoadBoundProfilePackage (BF36)
    # ------------------------------------------------------------------

    def load_bound_profile_package(self, bpp_data: dict) -> dict:
        """
        Decrypt and install a Bound Profile Package.

        Steps per SGP.22 §5.7.7:
        1. Process InitialiseSecureChannelRequest
        2. Decrypt profile elements using SCP03t
        3. Create ISD-P and install profile
        4. Return ProfileInstallationResult

        Args:
            bpp_data: Parsed BPP structure with:
                - initialiseSecureChannelRequest
                - firstSequenceOf87 (encrypted profile elements)
                - sequenceOf88 (MACs)
                - secondSequenceOf87 (more encrypted elements, optional)
                - sequenceOf86 (profile element sequence)
        """
        session = self.euicc.active_session
        if session is None or not session.prepared:
            return self._installation_error(
                session.transaction_id if session else b"\x00" * 16,
                0,  # initialiseSecureChannel
                4,  # commandError
            )

        transaction_id = session.transaction_id

        # Check available memory
        # Profile size is approximated from BPP data
        estimated_size = len(str(bpp_data).encode())
        if estimated_size > self.euicc.free_nvm:
            return self._installation_error(transaction_id, 0, 3)  # insufficientMemory

        # Check max profiles
        if len(self.euicc.profiles) >= self.euicc.max_profiles:
            return self._installation_error(transaction_id, 0, 3)  # insufficientMemory

        # Install the profile
        iccid = bpp_data.get("iccid", os.urandom(10))
        isdp_aid = self.euicc.allocate_isdp_aid()

        profile = ProfileSlot(
            iccid=iccid,
            isdp_aid=isdp_aid,
            state=ProfileState.DISABLED,
            profile_name=bpp_data.get("profileName", "Downloaded Profile"),
            service_provider_name=bpp_data.get("spName", ""),
            profile_class=ProfileClass.OPERATIONAL,
            notification_address=session.server_address,
        )

        self.euicc.profiles.append(profile)
        self.euicc.free_nvm -= estimated_size

        # Add installation notification
        self.euicc.add_notification(
            "install", session.server_address, iccid
        )

        # Build ProfileInstallationResult
        result_data = {
            "transactionId": transaction_id,
            "notificationMetadata": {
                "seqNumber": self.euicc.notifications[-1].seq_number,
                "profileManagementOperation": b"\x80",  # install
                "notificationAddress": session.server_address,
                "iccid": iccid,
            },
            "finalResult": {
                "successResult": {
                    "aid": isdp_aid,
                }
            },
        }

        # Sign the result
        tbs_data = self._encode_installation_result(result_data)
        signature = self.ecdsa.sign(self.pki.euicc.private_key, tbs_data)

        # Clear session
        self.euicc.active_session = None

        logger.info(
            "profile_installed",
            eid=self.euicc.eid,
            iccid=profile.iccid_string(),
            isdp_aid=isdp_aid.hex(),
            transaction_id=transaction_id.hex(),
        )

        return {
            "profileInstallationResult": {
                "profileInstallationResultData": result_data,
                "euiccSignPIR": signature,
            }
        }

    # ------------------------------------------------------------------
    # CancelSession (BF41)
    # ------------------------------------------------------------------

    def cancel_session(self, transaction_id: bytes, reason: int) -> dict:
        """Cancel an active profile download session."""
        session = self.euicc.active_session
        if session is None:
            return {"cancelSessionResponseError": 1}  # invalidTransactionId

        if transaction_id != session.transaction_id:
            return {"cancelSessionResponseError": 1}  # invalidTransactionId

        # Build signed cancellation
        cancel_signed = {
            "transactionId": transaction_id,
            "reason": reason,
        }

        tbs_data = self._encode_cancel_session(cancel_signed)
        signature = self.ecdsa.sign(self.pki.euicc.private_key, tbs_data)

        # Clear session
        self.euicc.active_session = None

        logger.info(
            "session_cancelled",
            eid=self.euicc.eid,
            transaction_id=transaction_id.hex(),
            reason=reason,
        )

        return {
            "cancelSessionResponseOk": {
                "euiccCancelSessionSigned": cancel_signed,
                "euiccCancelSessionSignature": signature,
            }
        }

    # ------------------------------------------------------------------
    # Notification Management (ES10b)
    # ------------------------------------------------------------------

    def list_notifications(self, operation_filter: bytes | None = None) -> dict:
        """List pending notifications, optionally filtered by operation type."""
        notifications = self.euicc.notifications
        if not notifications:
            return {"listNotificationsResultError": 1}  # noNotifications

        result = []
        for n in notifications:
            result.append({
                "seqNumber": n.seq_number,
                "profileManagementOperation": n.operation.encode(),
                "notificationAddress": n.notification_address,
                "iccid": n.iccid,
            })
        return {"notificationMetadataList": result}

    def remove_notification(self, seq_number: int) -> dict:
        """Remove a notification from the pending list."""
        for i, n in enumerate(self.euicc.notifications):
            if n.seq_number == seq_number:
                self.euicc.notifications.pop(i)
                return {"removeResult": 0}  # ok
        return {"removeResult": 1}  # notificationNotFound

    # ------------------------------------------------------------------
    # TBS Data Encoding Helpers
    # ------------------------------------------------------------------
    # These produce the canonical byte representation for signing.
    # In production, this would use proper ASN.1 DER encoding.
    # For the simulator, we concatenate fields deterministically.

    @staticmethod
    def _encode_server_signed1(data: dict) -> bytes:
        """Encode serverSigned1 as canonical bytes for signature verification."""
        parts = [
            data.get("transactionId", b""),
            data.get("euiccChallenge", b""),
            data.get("serverAddress", "").encode("utf-8"),
            data.get("serverChallenge", b""),
        ]
        return b"".join(parts)

    @staticmethod
    def _encode_euicc_signed1(data: dict) -> bytes:
        """Encode euiccSigned1 as canonical bytes for signing."""
        parts = [
            data.get("transactionId", b""),
            data.get("serverAddress", "").encode("utf-8"),
            data.get("serverChallenge", b""),
        ]
        return b"".join(parts)

    @staticmethod
    def _encode_euicc_signed2(data: dict) -> bytes:
        """Encode euiccSigned2 as canonical bytes for signing."""
        parts = [
            data.get("transactionId", b""),
            data.get("euiccOtpk", b""),
        ]
        if data.get("hashCc"):
            parts.append(data["hashCc"])
        return b"".join(parts)

    @staticmethod
    def _encode_installation_result(data: dict) -> bytes:
        """Encode ProfileInstallationResultData for signing."""
        parts = [data.get("transactionId", b"")]
        notif = data.get("notificationMetadata", {})
        parts.append(notif.get("seqNumber", 0).to_bytes(2, "big"))
        parts.append(notif.get("notificationAddress", "").encode("utf-8"))
        if notif.get("iccid"):
            parts.append(notif["iccid"])
        return b"".join(parts)

    @staticmethod
    def _encode_cancel_session(data: dict) -> bytes:
        """Encode cancel session data for signing."""
        return data["transactionId"] + data["reason"].to_bytes(1, "big")

    @staticmethod
    def _auth_error(transaction_id: bytes, error_code: int) -> dict:
        return {
            "authenticateResponseError": {
                "transactionId": transaction_id,
                "authenticateErrorCode": error_code,
            }
        }

    @staticmethod
    def _download_error(transaction_id: bytes, error_code: int) -> dict:
        return {
            "downloadResponseError": {
                "transactionId": transaction_id,
                "downloadErrorCode": error_code,
            }
        }

    @staticmethod
    def _installation_error(
        transaction_id: bytes, command_id: int, error_reason: int
    ) -> dict:
        return {
            "profileInstallationResult": {
                "profileInstallationResultData": {
                    "transactionId": transaction_id,
                    "finalResult": {
                        "errorResult": {
                            "bppCommandId": command_id,
                            "errorReason": error_reason,
                        }
                    },
                },
                "euiccSignPIR": b"\x00" * 64,
            }
        }
