# ConnectX eUICC Simulator

Virtual eUICC implementing GSMA SGP.22 v3.1 / SGP.32 v1.2 ES10 interfaces for eSIM IoT testing.

## Stack
- **Backend:** Python 3.12, FastAPI, Uvicorn
- **Crypto:** cryptography (ECDSA P-256, X.509, AES/SCP03t)
- **ASN.1:** asn1tools (DER encoding/decoding)
- **Deployment:** euicc.connectxiot.com (Hetzner), systemd + Nginx

## Architecture

```
IPA Simulator ──ES10──> eUICC Simulator
                         ├── ES10a: Configuration (addresses)
                         ├── ES10b: Authentication & profile download
                         ├── ES10c: Profile management (CRUD)
                         ├── ES10b-IoT: eIM config (SGP.32)
                         └── APDU: Raw STORE DATA interface
```

### Key Modules
- `app/crypto/certificates.py` — PKI chain: CI -> EUM -> eUICC (ECDSA P-256)
- `app/crypto/ecdsa_engine.py` — Signing, verification, ECDH key derivation
- `app/crypto/scp03t.py` — BPP decryption (AES-CBC/CMAC)
- `app/es10/es10b.py` — Profile download handlers (the 8-step auth dance)
- `app/es10/es10c.py` — Profile lifecycle (enable/disable/delete)
- `app/es10/es10b_iot.py` — SGP.32 eIM config + ESep package execution
- `app/services/euicc_manager.py` — Multi-instance eUICC management
- `app/services/apdu_handler.py` — STORE DATA APDU processing

## Commands
```bash
pip install -r requirements.txt
uvicorn app.main:app --port 8100 --reload   # Development
./deploy.sh                                   # Deploy to Hetzner
```

## Key Conventions
- All bytes transmitted as hex strings in JSON API
- Certificates as base64 DER
- ECDSA signatures in raw r||s format (64 bytes), NOT DER-encoded
- ICCID in BCD with nibble swap
- EID is 32 hex digits (16 bytes)
