#!/usr/bin/env bash
#
# bootstrap.sh — pasang Java Core System di VM dalam SATU perintah (non-interaktif).
#
#   cd ~/javacore && bash deploy/bootstrap.sh <DOMAIN>
#   contoh: cd ~/javacore && bash deploy/bootstrap.sh javacore-mup.duckdns.org
#
# Melakukan: salin kode → provisioning (PHP/Caddy/Node) → .env → Caddy(HTTPS) → build/migrate → backup.
#
set -euo pipefail

DOMAIN="${1:-}"
if [ -z "$DOMAIN" ]; then
  echo "Pemakaian: bash deploy/bootstrap.sh <domain>"
  echo "Contoh   : bash deploy/bootstrap.sh javacore-mup.duckdns.org"
  exit 1
fi

APP=/var/www/javacore
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> [1/6] Siapkan $APP + salin kode dari $SRC"
sudo mkdir -p "$APP"
sudo chown -R "$USER":"$USER" "$APP"
[ "$SRC" = "$APP" ] || cp -a "$SRC"/. "$APP"/
cd "$APP"

echo "==> [2/6] Provisioning server (PHP 8.4 + Caddy + Node) — sekali, beberapa menit"
bash deploy/provision.sh

echo "==> [3/6] Konfigurasi .env untuk domain: $DOMAIN"
if [ -f .env ]; then
  sed -i -E "s|^APP_ENV=.*|APP_ENV=production|"            .env
  sed -i -E "s|^APP_DEBUG=.*|APP_DEBUG=false|"             .env
  sed -i -E "s|^APP_URL=.*|APP_URL=https://$DOMAIN|"       .env
  sed -i -E "s|^LOG_LEVEL=.*|LOG_LEVEL=error|"             .env
  if grep -q '^DB_DATABASE=' .env; then
    sed -i -E "s|^DB_DATABASE=.*|DB_DATABASE=$APP/database/database.sqlite|" .env
  else
    echo "DB_DATABASE=$APP/database/database.sqlite" >> .env
  fi
  echo "    (memakai .env yang diupload — APP_KEY lama dipertahankan)"
else
  cp deploy/.env.production.example .env
  sed -i -E "s|^APP_URL=.*|APP_URL=https://$DOMAIN|" .env
  echo "    (.env baru dari template — APP_KEY akan digenerate oleh deploy.sh)"
fi

echo "==> [4/6] Pasang Caddy (HTTPS otomatis) untuk $DOMAIN"
sed -E "s|javacore-mup\.duckdns\.org|$DOMAIN|g" deploy/Caddyfile | sudo tee /etc/caddy/Caddyfile >/dev/null

echo "==> [5/6] Build + migrate + rilis"
bash deploy/deploy.sh

echo "==> [6/6] Reload Caddy + backup harian (cron)"
sudo systemctl reload caddy 2>/dev/null || sudo systemctl restart caddy
( crontab -l 2>/dev/null | grep -v 'backup-sqlite.sh'; echo "0 * * * * $APP/deploy/backup-sqlite.sh >/dev/null 2>&1" ) | crontab -

echo ""
echo "=================== SELESAI (OK) ==================="
echo "  Java Core System LIVE di:  https://$DOMAIN"
echo "  (Sertifikat HTTPS pertama butuh ~30 detik.)"
echo "  Setelah ini, ts.net/Tailscale Funnel tidak diperlukan lagi."
echo "================================================="
