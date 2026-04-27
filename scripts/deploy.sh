#!/usr/bin/env bash
# Deploy the eUICC + IPA simulators to euicc.connectxiot.com (Hetzner).
#
# Run from the dev machine. SSHs to the server, hard-resets a working
# copy of the monorepo to origin/main, syncs source + schemas + public
# trust material into the deployed install dirs, restarts both systemd
# units, and runs a health check.
#
# What is preserved (NOT overwritten):
#   - /opt/{euicc,ipa}-simulator/.env          local config
#   - /opt/euicc-simulator/euicc_simulator.db  SQLite state
#   - /opt/euicc-simulator/certs/<EID>/        per-EID auto-gen cert chains
#   - any logs in /var/log/captures/, journal
#
# Usage:
#   ./scripts/deploy.sh                 # deploy origin/main
#   REF=feature-branch ./scripts/deploy.sh
#   EUICC_HOST=root@1.2.3.4 ./scripts/deploy.sh

set -euo pipefail

HOST="${EUICC_HOST:-root@204.168.200.66}"
REF="${REF:-origin/main}"

echo "==> Deploying to $HOST (ref $REF)"

ssh "$HOST" "REF='$REF' bash -s" <<'REMOTE'
set -euo pipefail

REPO=/tmp/eUICC
EUICC=/opt/euicc-simulator
IPA=/opt/ipa-simulator

step() { printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }

step "Updating repo at $REPO (ref $REF)"
if [[ -d "$REPO/.git" ]]; then
    cd "$REPO"
    git fetch --all --prune
else
    git clone https://github.com/akhawanda/eUICC.git "$REPO"
    cd "$REPO"
    git fetch --all --prune
fi
git reset --hard "$REF"
echo "    HEAD: $(git log --oneline -1)"

step "Syncing eUICC simulator"
rsync -a --delete --exclude='__pycache__/' \
    "$REPO/euicc-simulator/app/" "$EUICC/app/"
rsync -a --exclude='__pycache__/' \
    "$REPO/euicc-simulator/asn1_schemas/" "$EUICC/asn1_schemas/"
# Public trust material — rsync without --delete so per-EID dirs survive.
mkdir -p "$EUICC/certs/_trusted_cis" "$EUICC/certs/sgp26_nist"
rsync -a "$REPO/euicc-simulator/certs/_trusted_cis/" "$EUICC/certs/_trusted_cis/"
rsync -a "$REPO/euicc-simulator/certs/sgp26_nist/"   "$EUICC/certs/sgp26_nist/"
if [[ -f "$REPO/euicc-simulator/requirements.txt" ]]; then
    if ! cmp -s "$REPO/euicc-simulator/requirements.txt" "$EUICC/requirements.txt" 2>/dev/null; then
        echo "    requirements.txt changed — pip installing"
        cp "$REPO/euicc-simulator/requirements.txt" "$EUICC/requirements.txt"
        "$EUICC/venv/bin/pip" install -q -r "$EUICC/requirements.txt"
    fi
fi

step "Syncing IPA simulator"
rsync -a --delete --exclude='__pycache__/' \
    "$REPO/ipa-simulator/app/" "$IPA/app/"
rsync -a --exclude='__pycache__/' \
    "$REPO/ipa-simulator/asn1_schemas/" "$IPA/asn1_schemas/"
mkdir -p "$IPA/certs/tls_roots"
rsync -a "$REPO/ipa-simulator/certs/tls_roots/" "$IPA/certs/tls_roots/"
if [[ -f "$REPO/ipa-simulator/requirements.txt" ]]; then
    if ! cmp -s "$REPO/ipa-simulator/requirements.txt" "$IPA/requirements.txt" 2>/dev/null; then
        echo "    requirements.txt changed — pip installing"
        cp "$REPO/ipa-simulator/requirements.txt" "$IPA/requirements.txt"
        "$IPA/venv/bin/pip" install -q -r "$IPA/requirements.txt"
    fi
fi

step "Fixing ownership"
chown -R www-data:www-data "$EUICC" "$IPA"

step "Restarting services"
systemctl restart euicc-sim ipa-sim
sleep 3
for svc in euicc-sim ipa-sim; do
    if systemctl is-active --quiet "$svc"; then
        echo "    $svc: active"
    else
        echo "    $svc: FAILED"
        journalctl -u "$svc" -n 30 --no-pager | tail -20
        exit 1
    fi
done

step "Health checks"
for url in \
    https://euicc.connectxiot.com/api/es10/health \
    https://euicc.connectxiot.com/api/ipa/health
do
    body=$(curl -sf "$url" || echo '{"status":"unreachable"}')
    echo "    $url -> $body"
done

step "Deploy complete"
REMOTE
