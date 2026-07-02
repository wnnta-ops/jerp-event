#!/usr/bin/env bash
# Update code HANYA BO (ipro-fo) — build + composer + migrate + build aset + restart.
# git pull LAKUKAN SENDIRI dulu. FO & database (jerp-mysql) TIDAK disentuh.
# Jalankan:  bash marger/up-bo.sh
set -euo pipefail
JERP_DIR="${JERP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
IPRO_DIR="$JERP_DIR/marger/ipro-fo_1/ipro-fo"
IPRO_FILES="-f docker-compose.yml -f docker-compose.override.yml -f docker-compose.shared.yml"

cd "$IPRO_DIR"
echo "==> 1/4 build & up BO (app + nginx + redis) — mysql TIDAK disentuh"
docker compose $IPRO_FILES up -d --build app nginx redis

echo "==> 2/4 composer install (autoloader class baru)"
docker compose $IPRO_FILES exec -T app composer install --no-dev --no-interaction --no-progress

echo "==> 3/4 migrate BO"
docker compose $IPRO_FILES exec -T app php artisan migrate --force

echo "==> 4/4 build aset frontend (Vue) + cache config"
docker compose $IPRO_FILES exec -T app sh -c "(npm ci --no-audit --no-fund || (echo '   npm ci gagal → reinstall bersih'; rm -rf node_modules package-lock.json && npm install --legacy-peer-deps --no-audit --no-fund)) && npm run build && php artisan config:cache"
docker restart ipro-fo-nginx >/dev/null 2>&1 || true   # re-resolve upstream (hindari 502)

echo "==> BO SELESAI (FO & database tidak disentuh).  BO -> :8090"
