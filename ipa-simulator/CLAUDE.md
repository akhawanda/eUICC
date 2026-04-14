# ConnectX IPA Simulator

IoT Profile Assistant simulator implementing ESipa, ES9+, and ES10 interfaces per GSMA SGP.32 v1.2.

## Stack
- **Backend:** Python 3.12, FastAPI, Uvicorn
- **HTTP Client:** httpx (async)
- **Deployment:** euicc.connectxiot.com (shared with eUICC simulator)

## Architecture

```
eIM Server ──ESipa──> IPA Simulator ──ES10──> eUICC Simulator
                           │
SM-DP+ Server ──ES9+──────┘
```

The IPA is the orchestrator/middleware:
- Polls eIM for packages (ESipa)
- Relays PSMO/eCO to eUICC (ESep via ES10b)
- Drives mutual authentication between SM-DP+ and eUICC
- Handles profile download (8-step ES9+/ES10b dance)

### Key Modules
- `app/clients/euicc_client.py` — ES10 client to eUICC simulator
- `app/clients/eim_client.py` — ESipa client to eIM server
- `app/clients/smdp_client.py` — ES9+ client to SM-DP+ server
- `app/orchestrator/profile_download.py` — Full download flow orchestrator
- `app/orchestrator/esipa_handler.py` — ESipa polling and ESep relay

## Commands
```bash
pip install -r requirements.txt
uvicorn app.main:app --port 8101 --reload   # Development
```

## Integration Points
- **eIM:** https://eim.connectxiot.com (existing Laravel server)
- **SM-DP+:** https://smdpplus.connectxiot.com (existing Laravel server)
- **eUICC:** http://localhost:8100 (eUICC simulator, same Hetzner server)
