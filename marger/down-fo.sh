#!/usr/bin/env bash
# Matikan HANYA FO (J-ERP) — hentikan app + worker + nginx.
# jerp-mysql SENGAJA DIBIARKAN HIDUP karena dipakai bareng BO (network shared-db),
# jadi BO tetap jalan. Hidupkan lagi:  bash marger/up-fo.sh
set -euo pipefail
JERP_DIR="${JERP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$JERP_DIR"

echo "==> Menghentikan FO (mysql tetap hidup untuk BO)"
# Semua service FO KECUALI mysql (dipakai bareng BO).
# Pakai flag -f (bukan COMPOSE_FILE=a:b) supaya portabel di Windows & Linux.
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  stop app nginx horizon scheduler reverb redis meilisearch

echo "==> FO BERHENTI (BO tidak disentuh, jerp-mysql tetap hidup)."
