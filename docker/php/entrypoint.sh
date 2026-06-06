#!/bin/sh
set -e

ROLE="${CONTAINER_ROLE:-app}"
APP_DIR="/var/www/html"

# Ensure writable runtime dirs (volume mounts may reset perms)
mkdir -p "$APP_DIR/storage/framework/sessions" \
         "$APP_DIR/storage/framework/views" \
         "$APP_DIR/storage/framework/cache/data" \
         "$APP_DIR/storage/logs" \
         "$APP_DIR/storage/app/public" \
         "$APP_DIR/storage/app/backups" \
         "$APP_DIR/bootstrap/cache" 2>/dev/null || true

# If running as root (default in dev), chown storage so PHP-FPM workers can write.
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true
fi
chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true

# Wait for MySQL (only the 'app' role boots migrations)
if [ "$ROLE" = "app" ] && [ -n "${DB_HOST:-}" ]; then
    echo "[entrypoint] Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}..."
    i=0
    until mysqladmin ping -h "$DB_HOST" -P "${DB_PORT:-3306}" --silent 2>/dev/null; do
        i=$((i+1))
        if [ "$i" -ge 30 ]; then
            echo "[entrypoint] MySQL did not become ready in 30s — continuing anyway."
            break
        fi
        sleep 1
    done

    # Auto-migrate in dev only (production migrates via CI/CD pipeline)
    if [ "${APP_ENV:-production}" != "production" ] && [ "${AUTO_MIGRATE:-true}" = "true" ]; then
        echo "[entrypoint] Auto-migrating (APP_ENV=${APP_ENV})..."
        php artisan migrate --force --no-interaction || echo "[entrypoint] migrate failed (continuing)"
    fi

    # Prime caches in production
    if [ "${APP_ENV:-production}" = "production" ]; then
        php artisan config:cache  || true
        php artisan route:cache   || true
        php artisan view:cache    || true
        php artisan event:cache   || true
    else
        # Dev: prime the FAST caches so config + 240 routes + Filament panels
        # aren't rebuilt from the (slow Windows bind-mounted) filesystem on every
        # request. Rebuilt on each boot, so editing config/routes is picked up by
        # `docker compose restart app`. We intentionally SKIP `view:cache` — it
        # takes minutes to compile all Filament Blade views; instead views compile
        # lazily on first hit and are then held by OPcache (validate_timestamps=0).
        # (Previously this ran `optimize:clear`, which wiped caches every boot and
        #  was the main cause of slow page loads in dev.)
        php artisan config:cache      || true
        php artisan route:cache       || true
        php artisan event:cache       || true
        php artisan filament:optimize || true
    fi
fi

echo "[entrypoint] Starting role=${ROLE}: $*"
exec "$@"
