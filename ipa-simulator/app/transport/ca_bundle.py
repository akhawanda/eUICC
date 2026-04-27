"""
Combined TLS CA bundle.

Public-PKI roots (Let's Encrypt etc., used by our SM-DP+ and eIM)
plus any extra PEMs dropped into `certs/tls_roots/` at the repo root —
typically GSMA RSP CI roots that sign production SM-DP+ TLS certs but
are not in any browser/system bundle.

At import time we concatenate certifi's bundle with every `*.pem` /
`*.crt` under `certs/tls_roots/` into a single temp file, and expose
its path as `CA_BUNDLE_PATH`. Pass that to `httpx.AsyncClient(verify=...)`.
"""
from __future__ import annotations

import logging
import tempfile
from pathlib import Path

import certifi

logger = logging.getLogger(__name__)


def _build_bundle() -> str:
    repo_root = Path(__file__).resolve().parents[2]
    extras_dir = repo_root / "certs" / "tls_roots"

    pieces: list[bytes] = []
    pieces.append(Path(certifi.where()).read_bytes())

    extras: list[Path] = []
    if extras_dir.is_dir():
        extras = sorted(p for p in extras_dir.iterdir() if p.suffix.lower() in (".pem", ".crt"))
        for p in extras:
            pieces.append(b"\n# --- " + str(p.name).encode() + b" ---\n")
            pieces.append(p.read_bytes())

    fd, path = tempfile.mkstemp(prefix="ipa_ca_bundle_", suffix=".pem")
    with open(fd, "wb") as f:
        for chunk in pieces:
            f.write(chunk)
            if not chunk.endswith(b"\n"):
                f.write(b"\n")

    logger.info(
        "ca_bundle_built path=%s extra_roots=%d (%s)",
        path,
        len(extras),
        ", ".join(p.name for p in extras) or "none",
    )
    return path


CA_BUNDLE_PATH: str = _build_bundle()
