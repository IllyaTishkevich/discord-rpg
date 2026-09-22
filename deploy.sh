#!/usr/bin/env bash
#
# Redeploys all three services (backend, activity, bot) on an already
# provisioned production server — see docs/PRODUCTION_SETUP.md §B.13 for
# what this automates and §B.1-B.12 for first-time server setup, which this
# script does NOT do (no nginx/PHP-FPM/PostgreSQL install, no systemd unit
# creation, no .env.local setup).
#
# Run as a sudo-capable user (not necessarily the app's own system user) —
# app-level commands (git/composer/npm) run as $DEPLOY_USER via `sudo -u`,
# service restarts run directly via `sudo systemctl`.
#
# Configure via environment variables if your server's layout differs from
# the docs' example paths/names:
#   APP_DIR          Repository root on the server (default: /opt/discord-rpg)
#   DEPLOY_USER       System user the app runs as (default: discord-rpg)
#   PHP_FPM_SERVICE   systemd unit for PHP-FPM (default: auto-detected)
#   BOT_SERVICE       systemd unit for the Discord bot (default: discord-rpg-bot)
#
# Example: APP_DIR=/var/www/discord-rpg PHP_FPM_SERVICE=php8.1-fpm ./deploy.sh

set -euo pipefail

APP_DIR="${APP_DIR:-/opt/discord-rpg}"
DEPLOY_USER="${DEPLOY_USER:-discord-rpg}"
BOT_SERVICE="${BOT_SERVICE:-discord-rpg-bot}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-}"

log() {
    printf '\n==> %s\n' "$1"
}

run() {
    if [ "$(id -un)" = "$DEPLOY_USER" ]; then
        "$@"
    else
        sudo -u "$DEPLOY_USER" "$@"
    fi
}

if [ ! -d "$APP_DIR/.git" ]; then
    echo "error: $APP_DIR doesn't look like the discord-rpg repository (no .git)." >&2
    echo "Set APP_DIR to the correct path, e.g.: APP_DIR=/var/www/discord-rpg ./deploy.sh" >&2
    exit 1
fi

if [ -z "$PHP_FPM_SERVICE" ]; then
    PHP_FPM_SERVICE=$(systemctl list-units --type=service --all --no-legend --plain 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | head -n1)
    if [ -z "$PHP_FPM_SERVICE" ]; then
        echo "error: couldn't auto-detect the PHP-FPM systemd unit." >&2
        echo "Set it explicitly, e.g.: PHP_FPM_SERVICE=php8.1-fpm ./deploy.sh" >&2
        exit 1
    fi
    log "Auto-detected PHP-FPM service: $PHP_FPM_SERVICE"
fi

log "git pull"
cd "$APP_DIR"
run git pull

log "backend: composer install"
cd "$APP_DIR/backend"
run composer install --no-dev --optimize-autoloader --no-interaction

log "backend: run migrations"
run php bin/console doctrine:migrations:migrate --no-interaction

log "backend: clear cache"
run php bin/console cache:clear --env=prod

log "backend: restart $PHP_FPM_SERVICE"
sudo systemctl restart "$PHP_FPM_SERVICE"

log "activity: npm install"
cd "$APP_DIR/activity"
run npm install

log "activity: build"
run npm run build

log "bot: npm install"
cd "$APP_DIR/bot"
run npm install --omit=dev

log "bot: restart $BOT_SERVICE"
sudo systemctl restart "$BOT_SERVICE"

log "Deploy complete."
echo "Reminder: if any slash commands changed, run 'npm run deploy-commands' in bot/ separately (see docs/PRODUCTION_SETUP.md §A.5) — it's not part of this script."
