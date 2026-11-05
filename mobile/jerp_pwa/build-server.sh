#!/usr/bin/env bash
# Build PWA untuk server dan salin ke public/approver/ FO (ikut image nginx saat deploy).
#   bash mobile/jerp_pwa/build-server.sh [https://j-erp.anisaproperti.com]
# Folder canvaskit/ tidak disalin: Flutter memuat CanvasKit dari CDN gstatic (bawaan).
set -euo pipefail

FO_URL="${1:-https://j-erp.anisaproperti.com}"
DIR="$(cd "$(dirname "$0")" && pwd)"
TUJUAN="$DIR/../../public/approver"

cd "$DIR"
flutter build web --release --base-href /approver/ --dart-define=API_BASE_URL="$FO_URL/api/mobile"

rm -rf "$TUJUAN"
mkdir -p "$TUJUAN"
rsync -a --exclude canvaskit/ --exclude .last_build_id build/web/ "$TUJUAN/"
echo "==> PWA siap di public/approver ($(du -sh "$TUJUAN" | cut -f1)), API: $FO_URL/api/mobile"
