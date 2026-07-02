#!/usr/bin/env bash
# Matikan HANYA BO (ipro-fo) — hentikan app + nginx + redis.
# jerp-mysql (punya FO, dipakai bareng) TIDAK disentuh, jadi FO tetap jalan.
# Hidupkan lagi:  bash marger/up-bo.sh
set -euo pipefail
JERP_DIR="${JERP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
IPRO_DIR="$JERP_DIR/marger/ipro-fo_1/ipro-fo"
IPRO_FILES="-f docker-compose.yml -f docker-compose.override.yml -f docker-compose.shared.yml"

cd "$IPRO_DIR"
echo "==> Menghentikan BO (jerp-mysql tidak disentuh)"
docker compose $IPRO_FILES stop app nginx redis

echo "==> BO BERHENTI (FO tidak disentuh)."
