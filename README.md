# ConnectX eUICC & IPA Simulator

Virtual eUICC and IoT Profile Assistant (IPA) simulator implementing GSMA SGP.22 v3.1 and SGP.32 v1.2 specifications for eSIM IoT testing.

## Architecture

```
┌──────────────┐     ESipa      ┌──────────────┐     ES10      ┌──────────────────┐
│  eIM Server  │ ◄────────────► │     IPA      │ ◄───────────► │  eUICC Simulator │
│ (Laravel)    │                │  Simulator   │               │  (Python/FastAPI)│
└──────────────┘                │ (Python/     │               │                  │
                                │  FastAPI)    │               │  ES10a: Config   │
┌──────────────┐     ES9+       │              │               │  ES10b: Auth/DL  │
│  SM-DP+      │ ◄────────────► │              │               │  ES10c: Profile  │
│ (Laravel)    │                │              │               │  ES10b-IoT: eIM  │
└──────────────┘                └──────────────┘               │  APDU: STORE DATA│
                                                               └──────────────────┘
                                       │
                                ESep (logical, end-to-end: eIM ◄──► eUICC)
```

## Components

### eUICC Simulator (`euicc-simulator/`)
Virtual secure element with full ES10 interfaces:
- **ES10a** — Configuration (SM-DP+/SM-DS addresses)
- **ES10b** — Mutual authentication & profile download (full ECDSA P-256)
- **ES10c** — Profile lifecycle management (enable/disable/delete)
- **ES10b-IoT** — SGP.32 eIM configuration (addEim/deleteEim/updateEim/listEim)
- **ESep** — End-to-end eUICC package processing (PSMO/eCO)
- **Crypto** — PKI chain (CI→EUM→eUICC), ECDH key derivation, SCP03t

### IPA Simulator (`ipa-simulator/`)
Middleware orchestrating between eIM, SM-DP+, and eUICC:
- **ESipa Client** — Poll eIM for packages, relay results
- **ES9+ Client** — Mutual authentication with SM-DP+
- **ES10 Client** — Relay commands to eUICC simulator
- **Profile Download** — Full 8-step authentication dance
- **ESep Relay** — Transparent relay of signed eIM packages

## Quick Start

```bash
# eUICC Simulator
cd euicc-simulator
pip install -r requirements.txt
uvicorn app.main:app --port 8100 --reload

# IPA Simulator (separate terminal)
cd ipa-simulator
pip install -r requirements.txt
EUICC_SIMULATOR_URL=http://localhost:8100 uvicorn app.main:app --port 8101 --reload
```

## Deployment

Target: `euicc.connectxiot.com` (Hetzner)

```bash
cd euicc-simulator
./deploy.sh
```

## Stack
- **Backend:** Python 3.12, FastAPI, Uvicorn
- **Crypto:** cryptography (ECDSA P-256, X.509, AES/SCP03t)
- **ASN.1:** asn1tools (DER encoding/decoding per SGP.22)
- **HTTP:** httpx (async client)

## GSMA Specifications
- SGP.22 v3.1 — RSP Technical Specification (Consumer)
- SGP.32 v1.2 — eSIM IoT Technical Specification
- SGP.31 v1.2 — eSIM IoT Architecture and Requirements
- GlobalPlatform Card Specification v2.3.1 — STORE DATA APDU
