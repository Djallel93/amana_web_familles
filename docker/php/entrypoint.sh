#!/usr/bin/env bash
# docker/php/entrypoint.sh
# Runs once per `docker compose up` on the app container, before handing
# off to CMD (php artisan serve). Idempotent — safe to run on every boot.
set -e

cd /var/www/amana/amana_web_familles

# --- Private repo auth (amana/shared over Composer VCS) --------------------
# Same mechanism as docs/composer-auth.md §2 and the CI workflows — one git
# URL rewrite covers Composer's clone of amana/shared. Only needed if you
# are NOT using composer.local.json (see docs/local-development.md); if a
# local path repo is in play, this token is never even contacted.
if [ -n "${AMANA_REPOS_PAT:-}" ]; then
    git config --global url."https://x-access-token:${AMANA_REPOS_PAT}@github.com/".insteadOf "https://github.com/"
fi
git config --global --add safe.directory /var/www/amana/amana_web_familles
git config --global --add safe.directory /var/www/amana/amana_shared
git config --global --add safe.directory /var/www/amana/amana_shared_ui

# --- .env ---------------------------------------------------------------
if [ ! -f .env ]; then
    echo "==> No .env found, copying .env.example"
    cp .env.example .env
fi

# --- Composer ------------------------------------------------------------
# If composer.local.json exists (see docs/local-development.md), the
# merge-plugin picks it up automatically — no extra flag needed here.
echo "==> composer install"
composer install --no-interaction --no-progress

if grep -q '^APP_KEY=$' .env; then
    echo "==> Generating APP_KEY"
    php artisan key:generate --ansi
fi

# --- Wait for MySQL (only relevant if DB_CONNECTION=mysql) ---------------
if grep -q '^DB_CONNECTION=mysql' .env; then
    echo "==> Waiting for MySQL at ${DB_HOST:-mysql}..."
    tries=0
    until mysqladmin ping -h "${DB_HOST:-mysql}" -u"${DB_USERNAME:-root}" -p"${DB_PASSWORD:-root}" --silent 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "MySQL did not become ready in time" >&2
            break
        fi
        sleep 1
    done
fi

# --- Storage / cache dirs + migrations -----------------------------------
mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache
php artisan migrate --force
php artisan storage:link 2>/dev/null || true

# --- Hand bind-mounted write targets back to your host user --------------
# Everything above ran as root (see docker/php/Dockerfile for why). vendor/
# is a named volume, invisible on the host, so its root ownership doesn't
# matter — but storage/ and bootstrap/cache/ are bind-mounted straight
# from your host checkout, and anything Laravel wrote into them just now
# (log files, cached views, the migrations marker, etc.) is currently
# root-owned there too. HOST_UID/HOST_GID come from docker-compose.yml,
# which reads them from your host's `id -u`/`id -g` via .env — chowning
# back to those means `git status`, deleting a log file, etc. from outside
# Docker doesn't need sudo.
chown -R "${HOST_UID:-1000}:${HOST_GID:-1000}" storage bootstrap/cache 2>/dev/null || true

echo "==> Ready"
exec "$@"
