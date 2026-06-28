#!/usr/bin/env bash
# Java Core System — deploy / update otomatis (Caddy + PHP 8.4 FPM, SQLite).
# Pakai untuk RILIS PERTAMA maupun UPDATE berikutnya.  Jalankan:  bash deploy/deploy.sh
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"
echo "==> Deploy di: $APP_DIR"

# 1) .env wajib ada
if [ ! -f .env ]; then
    cp deploy/.env.production.example .env
    echo "!! .env dibuat dari template — ISI APP_KEY (dari .env lama) & APP_URL, lalu jalankan ulang."
    exit 1
fi

# 2) Maintenance mode
php artisan down --retry=15 || true

# 3) Kode terbaru (bila pakai git)
[ -d .git ] && { git pull --ff-only || echo "  (lewati git pull)"; }

# 4) Dependensi PHP (tanpa dev, optimized)
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# 5) Build aset frontend (Vite + vue-tsc)
if command -v npm >/dev/null 2>&1; then
    npm ci
    npm run build
fi

# 6) APP_KEY hanya digenerate bila kosong (jangan timpa key lama → data terenkripsi tetap valid)
grep -qE '^APP_KEY=base64:.+' .env || php artisan key:generate --force

# 7) DB SQLite (pakai data yang sudah diupload) + WAL untuk konkurensi
mkdir -p database storage/backups
[ -f database/database.sqlite ] || { touch database/database.sqlite; echo "  (buat database.sqlite baru)"; }
sqlite3 database/database.sqlite "PRAGMA journal_mode=WAL;" || true
php artisan migrate --force

# 8) Symlink storage + cache config/route/view
php artisan storage:link 2>/dev/null || true
php artisan optimize

# 9) Izin tulis untuk PHP-FPM (www-data): storage / cache / DB harus group-writable
sudo chown -R "$USER":www-data storage bootstrap/cache database 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache database

# 10) Reload PHP-FPM (bersihkan opcache) + restart worker antrian (bila aktif)
sudo systemctl reload php8.4-fpm 2>/dev/null || true
sudo systemctl restart javacore-worker 2>/dev/null || true

# 11) Online kembali
php artisan up
echo "==> Deploy SELESAI. Aplikasi online kembali."
