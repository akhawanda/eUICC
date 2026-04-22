"""
IPA Simulator — FastAPI Application Entry Point.

IoT Profile Assistant simulator implementing:
- ESipa client (polling eIM for packages)
- ES9+ client (mutual authentication with SM-DP+)
- ES10 client (relaying commands to eUICC simulator)
- Profile download orchestration (the 8-step authentication dance)

Server: euicc.connectxiot.com (shared with eUICC simulator)
"""

from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
import structlog

from .api.routes import router, set_orchestrators
from .api.trace_middleware import TraceMiddleware
from .config import settings
from .clients.euicc_client import EuiccClient
from .clients.eim_client import EimClient
from .clients.smdp_client import SmdpClient
from .orchestrator.profile_download import ProfileDownloadOrchestrator
from .orchestrator.esipa_handler import EsipaHandler

# Configure structured logging
structlog.configure(
    processors=[
        structlog.stdlib.filter_by_level,
        structlog.stdlib.add_logger_name,
        structlog.stdlib.add_log_level,
        structlog.processors.TimeStamper(fmt="iso"),
        structlog.processors.JSONRenderer(),
    ],
    wrapper_class=structlog.stdlib.BoundLogger,
    context_class=dict,
    logger_factory=structlog.stdlib.LoggerFactory(),
)

logger = structlog.get_logger()


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Initialize clients and orchestrators on startup."""
    # Create clients
    euicc_client = EuiccClient(settings.euicc_simulator_url)
    eim_client = EimClient(settings.eim_url)
    smdp_client = SmdpClient(settings.smdp_url)

    # Create orchestrators
    dl = ProfileDownloadOrchestrator(euicc_client, smdp_client)
    esipa = EsipaHandler(euicc_client, eim_client, dl)

    set_orchestrators(dl, esipa)

    logger.info(
        "startup_complete",
        euicc_url=settings.euicc_simulator_url,
        eim_url=settings.eim_url,
        smdp_url=settings.smdp_url,
    )

    yield

    # Cleanup
    await euicc_client.close()
    await eim_client.close()
    await smdp_client.close()
    logger.info("shutdown")


app = FastAPI(
    title="ConnectX IPA Simulator",
    description=(
        "IoT Profile Assistant simulator implementing ESipa, ES9+, and ES10 "
        "interfaces per GSMA SGP.32 v1.2. Orchestrates profile download "
        "between eIM, SM-DP+, and eUICC."
    ),
    version="1.0.0",
    lifespan=lifespan,
    docs_url="/api/docs",
    redoc_url="/api/redoc",
)

# CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "https://euicc.connectxiot.com",
        "http://localhost:8000",
        "http://localhost:3000",
        "http://localhost:5173",
    ],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Captures outbound HTTP to eIM / SM-DP+ / eUICC-sim and splices the
# per-request trace into /api/ipa/esipa/* and /api/ipa/download/*
# JSON responses under `_trace`.
app.add_middleware(TraceMiddleware)

app.include_router(router)


@app.get("/")
async def root():
    return {
        "service": "ConnectX IPA Simulator",
        "version": "1.0.0",
        "spec": "GSMA SGP.32 v1.2",
        "interfaces": {
            "ESipa": "eIM <-> IPA communication",
            "ES9+": "IPA <-> SM-DP+ authentication & download",
            "ES10": "IPA <-> eUICC (via eUICC simulator)",
        },
        "endpoints": {
            "eIM": settings.eim_url,
            "SM-DP+": settings.smdp_url,
            "eUICC": settings.euicc_simulator_url,
        },
        "docs": "/api/docs",
    }


@app.get("/health")
async def health():
    from .api.routes import esipa_handler
    return {
        "status": "healthy",
        "registeredDevices": len(esipa_handler.sessions) if esipa_handler else 0,
    }
