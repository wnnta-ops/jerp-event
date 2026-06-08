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

# Manifest paket (packages.php/services.php) bisa BASI — mis. setelah `docker compose
# down -v` lalu vendor di-reseed `--no-dev`, atau bootstrap/cache lama nyangkut di
# bind-mount host (dev). Hapus supaya Laravel membangun ulang dari vendor/composer/
# installed.json TERKINI → cegah "Class ...\ServiceProvider not found" (mis. debugbar).
rm -f "$APP_DIR/bootstrap/cache/packages.php" "$APP_DIR/bootstrap/cache/services.php" 2>/dev/null || true

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

    if [ "${APP_ENV:-production}" = "production" ]; then
        # ── Produksi: first-boot turnkey (`docker compose up -d --build` cukup) ──

        # 1) APP_KEY — generate sekali bila belum ada di .env (yang di-mount).
        if ! grep -qE '^APP_KEY=.+' "$APP_DIR/.env" 2>/dev/null; then
            echo "[entrypoint] APP_KEY kosong → generate..."
            php artisan key:generate --force --no-interaction || echo "[entrypoint] key:generate gagal"
        fi

        # 2) Migrasi (idempotent — hanya yang pending).
        if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
            echo "[entrypoint] Migrating (--force)..."
            php artisan migrate --force --no-interaction || echo "[entrypoint] migrate gagal (lanjut)"
        fi

        # 3) Seed HANYA bila DB masih kosong (deploy pertama) — cegah duplikasi.
        if [ "${AUTO_SEED:-true}" = "true" ]; then
            USERS=$(php artisan tinker --execute='echo \Illuminate\Support\Facades\Schema::hasTable("users") ? \App\Models\User::query()->count() : 1;' 2>/dev/null | tail -n1 | tr -dc '0-9')
            if [ "$USERS" = "0" ]; then
                echo "[entrypoint] DB kosong → seeding awal..."
                php artisan db:seed --force --no-interaction || echo "[entrypoint] seed gagal (lanjut)"
            fi
        fi

        # 4) Symlink storage (harmless bila sudah ada; web image juga punya symlink).
        php artisan storage:link --no-interaction 2>/dev/null || true

        # 5) Prime caches produksi.
        php artisan config:cache  || true
        php artisan route:cache   || true
        php artisan view:cache    || true
        php artisan event:cache   || true
    else
        # Auto-migrate dev
        if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
            echo "[entrypoint] Auto-migrating dev (APP_ENV=${APP_ENV})..."
            php artisan migrate --force --no-interaction || echo "[entrypoint] migrate failed (continuing)"
        fi

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
