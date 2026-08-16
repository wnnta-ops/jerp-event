#!/usr/bin/env bash
# STOP semua stack J-ERP dalam satu perintah: Mobile + BO (ipro-fo) + FO (J-ERP).
# Default: `stop` (container & data AMAN, tinggal `up.sh` lagi).
#   bash marger/down.sh          # stop (aman, cepat)
#   REMOVE=1 bash marger/down.sh # down -v? TIDAK. down (hapus container, volume TETAP)
set -euo pipefail

JERP_DIR="${JERP_DIR:-$(pwd)}"
IPRO_DIR="${IPRO_DIR:-$JERP_DIR/marger/ipro-fo_1/ipro-fo}"
# prod.yml PALING AKHIR: menutup port Redis/Meili/Reverb/MinIO/Mailpit yang
# diterbitkan setelan dev ke 0.0.0.0. Urutan -f menentukan, yang belakangan menang.
IPRO_FILES="-f docker-compose.yml -f docker-compose.override.yml -f docker-compose.shared.yml -f docker-compose.prod.yml"
ACT="stop"; [ "${REMOVE:-0}" = "1" ] && ACT="down"   # down TIDAK pakai -v -> volume/data aman

echo "==> Mobile $ACT"; ( cd "$JERP_DIR" && docker compose -f docker-compose.mobile.yml $ACT ) || true
echo "==> BO $ACT";     ( cd "$IPRO_DIR" && docker compose $IPRO_FILES $ACT ) || true
echo "==> FO $ACT";     ( cd "$JERP_DIR" && docker compose $ACT ) || true
echo "==> Selesai ($ACT). Data MySQL & volume tetap aman. Start lagi: bash marger/up.sh"
