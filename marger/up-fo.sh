#!/usr/bin/env bash
# Update code HANYA FO (J-ERP) — build image + migrate + restart nginx.
# git pull LAKUKAN SENDIRI dulu. BO & database (jerp-mysql) TIDAK disentuh.
# Jalankan:  bash marger/up-fo.sh
set -euo pipefail
JERP_DIR="${JERP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$JERP_DIR"

echo "==> 1/2 build & up FO (app + nginx + worker) — mysql TIDAK disentuh"
# Hanya service ber-kode; mysql/redis/meili sengaja dikecualikan agar DB tak mati.
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  up -d --build app nginx horizon scheduler reverb

echo "==> 2/2 migrate FO"
docker exec jerp-app php artisan migrate --force
docker restart jerp-nginx >/dev/null 2>&1 || true      # re-resolve upstream (hindari 502)

echo "==> FO SELESAI (BO & database tidak disentuh).  FO -> :8081"
