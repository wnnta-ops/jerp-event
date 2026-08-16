#!/usr/bin/env bash
# Matikan HANYA BO (ipro-fo) — hentikan app + nginx + redis.
# jerp-mysql (punya FO, dipakai bareng) TIDAK disentuh, jadi FO tetap jalan.
# Hidupkan lagi:  bash marger/up-bo.sh
set -euo pipefail
JERP_DIR="${JERP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
IPRO_DIR="$JERP_DIR/marger/ipro-fo_1/ipro-fo"
# prod.yml PALING AKHIR: menutup port Redis/Meili/Reverb/MinIO/Mailpit yang
# diterbitkan setelan dev ke 0.0.0.0. Urutan -f menentukan, yang belakangan menang.
IPRO_FILES="-f docker-compose.yml -f docker-compose.override.yml -f docker-compose.shared.yml -f docker-compose.prod.yml"

cd "$IPRO_DIR"
echo "==> Menghentikan BO (jerp-mysql tidak disentuh)"
docker compose $IPRO_FILES stop app nginx redis

echo "==> BO BERHENTI (FO tidak disentuh)."
