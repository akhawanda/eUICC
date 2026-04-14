"""
eUICC Simulator — FastAPI Application Entry Point.

A virtual eUICC that implements ES10a/ES10b/ES10c interfaces
per GSMA SGP.22 v3.1 and SGP.32 v1.2.

Server: euicc.connectxiot.com
"""

import os
from pathlib import Path
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
import structlog

from .api.routes import router, set_manager
from .services.euicc_manager import EuiccManager

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

# Configuration
BASE_DIR = Path(__file__).parent.parent
CERTS_DIR = Path(os.getenv("EUICC_CERTS_DIR", str(BASE_DIR / "certs")))
SMDP_ADDRESS = os.getenv("SMDP_ADDRESS", "smdpplus.connectxiot.com")
EIM_FQDN = os.getenv("EIM_FQDN", "eim.connectxiot.com")
CREATE_TEST_DATA = os.getenv("CREATE_TEST_DATA", "true").lower() == "true"


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Initialize eUICC manager and test data on startup."""
    mgr = EuiccManager(CERTS_DIR)
    set_manager(mgr)

    if CREATE_TEST_DATA:
        mgr.create_test_euiccs(SMDP_ADDRESS, EIM_FQDN)
        logger.info(
            "startup_complete",
            euiccs=len(mgr.instances),
            smdp=SMDP_ADDRESS,
            eim=EIM_FQDN,
        )

    yield

    logger.info("shutdown")


app = FastAPI(
    title="ConnectX eUICC Simulator",
    description=(
        "Virtual eUICC implementing GSMA SGP.22/SGP.32 ES10 interfaces. "
        "Simulates the secure element for eSIM IoT testing."
    ),
    version="1.0.0",
    lifespan=lifespan,
    docs_url="/api/docs",
    redoc_url="/api/redoc",
)

# CORS — allow IPA simulator and Laravel frontend
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

app.include_router(router)


@app.get("/")
async def root():
    return {
        "service": "ConnectX eUICC Simulator",
        "version": "1.0.0",
        "spec": "GSMA SGP.22 v3.1 / SGP.32 v1.2",
        "interfaces": ["ES10a", "ES10b", "ES10c", "ES10b-IoT"],
        "docs": "/api/docs",
    }


@app.get("/health")
async def health():
    from .api.routes import manager
    return {
        "status": "healthy",
        "euiccs": len(manager.instances) if manager else 0,
    }
