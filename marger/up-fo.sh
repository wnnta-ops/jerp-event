#!/usr/bin/env bash
# Deploy HANYA FO (J-ERP) — build + migrate + restart. BO TIDAK disentuh.
# Jalankan:  bash marger/up-fo.sh
set -euo pipefail
JERP_DIR="${JERP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$JERP_DIR"

echo "==> 1/3 git pull"
git pull

echo "==> 2/3 build & up FO (image di-rebuild → composer/autoloader ikut ter-update)"
# Pakai flag -f (bukan COMPOSE_FILE=a:b) supaya portabel di Windows & Linux.
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

echo "==> 3/3 migrate FO"
docker exec jerp-app php artisan migrate --force
docker restart jerp-nginx >/dev/null 2>&1 || true      # re-resolve upstream (hindari 502)

echo "==> FO SELESAI (BO tidak disentuh).  FO -> :8081"
