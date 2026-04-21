#!/usr/bin/env bash
# Server-side bootstrap: install Laravel 12 base, overlay our custom files, run migrations.
# Run this ONCE on the server after the first deploy.  Idempotent — safe to re-run.
#
# Preconditions on server:
#   - PHP 8.3 + required extensions (mbstring, xml, sqlite3, curl, intl, bcmath, gd, zip)
#   - Composer 2.x on PATH
#   - Node 20 + npm on PATH
#   - Nginx + php-fpm already installed (config added later by deploy.sh)

set -euo pipefail

SRC_DIR="${SRC_DIR:-/var/www/euicc-frontend-src}"
APP_DIR="${APP_DIR:-/var/www/euicc-frontend}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@connectxiot.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-ChangeMeOnFirstLogin!}"

log() { echo -e "\e[36m[bootstrap]\e[0m $*"; }

# ---------------------------------------------------------------- 1. base skeleton
if [[ ! -f "$APP_DIR/artisan" ]]; then
    log "Creating Laravel 12 base at $APP_DIR"
    mkdir -p "$(dirname "$APP_DIR")"
    composer create-project --prefer-dist --no-interaction laravel/laravel:^12 "$APP_DIR"
else
    log "Laravel base already exists at $APP_DIR — skipping create-project"
fi

# ---------------------------------------------------------------- 2. extra packages
log "Adding Livewire + Guzzle + Breeze"
cd "$APP_DIR"
composer require --no-interaction livewire/livewire:^3.5 guzzlehttp/guzzle:^7.8
composer require --no-interaction --dev laravel/breeze:^2.4

if [[ ! -d "$APP_DIR/resources/views/auth" ]]; then
    log "Installing Breeze (blade + livewire stack)"
    php artisan breeze:install blade --no-interaction
    npm install
fi

# ---------------------------------------------------------------- 3. overlay custom source
log "Overlaying custom source from $SRC_DIR"
# Files we ship: app/, database/migrations/, resources/views/, routes/, config/, .env.example
for sub in app database/migrations database/seeders resources/views routes config; do
    if [[ -d "$SRC_DIR/$sub" ]]; then
        mkdir -p "$APP_DIR/$sub"
        cp -R "$SRC_DIR/$sub/." "$APP_DIR/$sub/"
        log "  + $sub"
    fi
done

# Merge our routes/api.php registration + middleware alias into bootstrap/app.php
# (hand-edited patch — Laravel 12 uses bootstrap/app.php, not Kernel.php)
python3 "$SRC_DIR/scripts/patch_bootstrap.py" "$APP_DIR/bootstrap/app.php"

# ---------------------------------------------------------------- 4. env
if [[ ! -f "$APP_DIR/.env" ]]; then
    cp "$SRC_DIR/.env.example" "$APP_DIR/.env"
    php artisan key:generate --force
    # Generate a seed token if the placeholder is still present
    if grep -q 'replace-me-with-long-random-string' "$APP_DIR/.env"; then
        TOKEN=$(openssl rand -hex 32)
        sed -i "s/replace-me-with-long-random-string/$TOKEN/" "$APP_DIR/.env"
    fi
    sed -i "s|/var/www/euicc-frontend|$APP_DIR|g" "$APP_DIR/.env"
fi

# ---------------------------------------------------------------- 5. DB + migrations
log "Running migrations"
touch "$APP_DIR/database/database.sqlite"
php artisan migrate --force

# ---------------------------------------------------------------- 6. admin user
log "Seeding admin user (if not exists)"
php artisan tinker --execute="
    \$email = '$ADMIN_EMAIL';
    if (! App\\Models\\User::where('email', \$email)->exists()) {
        App\\Models\\User::create([
            'name' => 'Admin',
            'email' => \$email,
            'password' => bcrypt('$ADMIN_PASSWORD'),
            'email_verified_at' => now(),
        ]);
        echo 'admin user created';
    } else {
        echo 'admin user already exists';
    }
"

# ---------------------------------------------------------------- 7. assets
log "Building assets"
npm run build

# ---------------------------------------------------------------- 8. perms
log "Setting permissions"
chown -R www-data:www-data "$APP_DIR"
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

log "Done — admin credentials: $ADMIN_EMAIL / $ADMIN_PASSWORD"
log "Remember to rotate SIM_SEED_TOKEN and ADMIN_PASSWORD in $APP_DIR/.env"
