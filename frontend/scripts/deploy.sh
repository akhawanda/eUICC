#!/usr/bin/env bash
# Local → Hetzner deploy.
# Tars the frontend source, scps it to the server, runs bootstrap.sh on first
# deploy or overlays + migrates on subsequent deploys.
set -euo pipefail

SERVER="${SERVER:-root@204.168.200.66}"
SRC_DIR_REMOTE="/var/www/euicc-frontend-src"
APP_DIR_REMOTE="/var/www/euicc-frontend"

HERE="$(cd "$(dirname "$0")/.." && pwd)"
TARBALL="/tmp/euicc-frontend-src.tar.gz"

echo "[deploy] packaging $HERE"
tar -czf "$TARBALL" \
    -C "$HERE" \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='vendor' \
    .

echo "[deploy] copying to $SERVER"
ssh "$SERVER" "mkdir -p $SRC_DIR_REMOTE"
scp "$TARBALL" "$SERVER:$SRC_DIR_REMOTE/src.tar.gz"

echo "[deploy] extracting + overlay + migrate"
ssh "$SERVER" bash -s <<REMOTE
    set -euo pipefail
    cd "$SRC_DIR_REMOTE"
    tar -xzf src.tar.gz
    rm src.tar.gz

    if [[ ! -f "$APP_DIR_REMOTE/artisan" ]]; then
        echo "[deploy] first-time bootstrap"
        bash "$SRC_DIR_REMOTE/scripts/bootstrap.sh"
    else
        echo "[deploy] overlay on existing install"
        for sub in app database/migrations database/seeders resources/views routes config; do
            if [[ -d "$SRC_DIR_REMOTE/\$sub" ]]; then
                cp -R "$SRC_DIR_REMOTE/\$sub/." "$APP_DIR_REMOTE/\$sub/"
            fi
        done
        cd "$APP_DIR_REMOTE"
        php artisan migrate --force
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        npm run build
        chown -R www-data:www-data "$APP_DIR_REMOTE"
        systemctl reload php8.4-fpm || systemctl reload php8.3-fpm || systemctl reload php8.2-fpm
    fi
REMOTE

rm -f "$TARBALL"
echo "[deploy] done"
