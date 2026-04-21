# ConnectX eUICC / IPA Simulator Frontend

Standalone Laravel 12 dashboard for the ConnectX eUICC and IPA simulators.

**Domain:** `https://euicc.connectxiot.com` (Hetzner, 204.168.200.66)

## Architecture

```
Browser ──► Nginx (443)
           │
           ├─ /              ─► PHP-FPM (Laravel)           ← this app
           ├─ /api/es10/*    ─► 127.0.0.1:8100 (eUICC sim)
           └─ /api/ipa/*     ─► 127.0.0.1:8101 (IPA sim)
```

Laravel is the **source of truth** for device definitions. On startup, each
simulator fetches `/api/seed` from this app and re-hydrates its in-memory state.

## Pages

- `/dashboard` — overview + simulator health
- `/devices` — list / create / edit / clone / delete virtual devices (EID-keyed)
- `/devices/{id}` — device detail: profiles, eIM associations, certs, actions
- `/ipa-console` — Comprion-style: pick one or many devices, click operation
  buttons (Retrieve eIM Package, Profile Download, Add eIM, Execute ESep, ...)
- `/server-status` — health + recent IPA sessions

## Source layout

This folder holds **only the custom source** (migrations, models, Livewire,
views, routes, config). The Laravel base skeleton is installed by
`scripts/bootstrap.sh` on the server and our files are overlaid on top. No PHP
runs locally.

```
frontend/
├── scripts/bootstrap.sh     — server: composer create-project + overlay
├── scripts/deploy.sh        — local:  tar + scp + trigger bootstrap
├── composer-additions.json  — extra packages (livewire, guzzle)
├── .env.example             — env template
├── app/                     — models, livewire, services, controllers
├── database/migrations/     — 4 tables
├── resources/views/         — layouts + livewire views
├── routes/web.php + api.php
└── config/simulators.php    — FastAPI endpoint addresses
```

## Auth

Laravel Breeze, same pattern as eIM — login-gated, user roles. Seeded
admin user created by `bootstrap.sh`.

## Deploy

```bash
# one-time on server (runs composer create-project + installs deps)
ssh root@204.168.200.66 'bash /var/www/euicc-frontend-src/scripts/bootstrap.sh'

# subsequent deploys (from Windows dev machine)
bash scripts/deploy.sh
```
