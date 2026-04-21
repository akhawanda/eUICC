"""
eUICC Simulator — FastAPI Application Entry Point.

A virtual eUICC that implements ES10a/ES10b/ES10c interfaces
per GSMA SGP.22 v3.1 and SGP.32 v1.2.

Server: euicc.connectxiot.com
"""

from pathlib import Path
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
import structlog

from .api.routes import router, set_manager
from .config import settings
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


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Initialize eUICC manager, database, and test data on startup."""
    from .models.database import init_db
    await init_db(settings.database_url)

    mgr = EuiccManager(Path(settings.euicc_certs_dir))
    set_manager(mgr)

    # Load persisted eUICCs from database
    from .models.database import load_persisted_euiccs
    await load_persisted_euiccs(mgr)

    # Re-hydrate from Laravel (source of truth) — adds any devices defined in
    # the dashboard that aren't already in local SQLite. Failure is non-fatal.
    from .services.laravel_seeder import reseed_from_laravel
    await reseed_from_laravel(
        mgr,
        settings.laravel_seed_url,
        settings.laravel_seed_token,
    )

    if settings.create_test_data and not mgr.instances:
        mgr.create_test_euiccs(settings.smdp_address, settings.eim_fqdn)

    logger.info(
        "startup_complete",
        euiccs=len(mgr.instances),
        smdp=settings.smdp_address,
        eim=settings.eim_fqdn,
    )

    yield

    # Persist state before shutdown
    from .models.database import persist_euiccs
    await persist_euiccs(mgr)
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
