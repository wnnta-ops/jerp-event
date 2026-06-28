#!/usr/bin/env bash
#
# provision.sh — siapkan VM Oracle Cloud Always Free (Ubuntu 22.04/24.04) untuk Java Core System.
# Web server: Caddy (HTTPS otomatis gratis) + PHP 8.4 FPM. Stack Laravel/PHP/SQLite TIDAK diubah.
# Jalankan SEKALI di VM sebagai user 'ubuntu' (punya sudo). Aman diulang.
#
#   bash deploy/provision.sh
#
set -euo pipefail

echo "==> [1/8] Update sistem + PPA PHP 8.4 (ondrej)"
sudo apt-get update -y
sudo apt-get install -y software-properties-common ca-certificates curl gnupg unzip git apt-transport-https
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -y

echo "==> [2/8] PHP 8.4 (FPM + CLI) + ekstensi yang dibutuhkan"
sudo apt-get install -y \
  php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-bcmath php8.4-gd php8.4-intl
sudo update-alternatives --set php /usr/bin/php8.4 || true   # 'php' = 8.4
php -v

echo "==> [3/8] Composer"
command -v composer >/dev/null 2>&1 || \
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
composer --version

echo "==> [4/8] Node.js 20 (untuk build Vite/vue-tsc)"
command -v node >/dev/null 2>&1 || { curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - ; sudo apt-get install -y nodejs ; }
node -v && npm -v

echo "==> [5/8] Caddy (web server + HTTPS otomatis Let's Encrypt)"
if ! command -v caddy >/dev/null 2>&1; then
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list >/dev/null
  sudo apt-get update -y && sudo apt-get install -y caddy
fi
caddy version

echo "==> [6/8] Chromium + sqlite3 (spatie/browsershot PDF & backup DB)"
sudo apt-get install -y sqlite3 fonts-liberation chromium-browser || sudo apt-get install -y sqlite3 fonts-liberation chromium || true

echo "==> [7/8] Folder aplikasi + folder log"
sudo mkdir -p /var/www/javacore /var/log/caddy
sudo chown -R "$USER":"$USER" /var/www/javacore
sudo chown -R caddy:caddy /var/log/caddy 2>/dev/null || true

echo "==> [8/8] Firewall lokal 80/443 (Oracle Ubuntu memblokir default)"
sudo iptables -C INPUT -p tcp --dport 80  -j ACCEPT 2>/dev/null || sudo iptables -I INPUT 6 -p tcp --dport 80  -j ACCEPT
sudo iptables -C INPUT -p tcp --dport 443 -j ACCEPT 2>/dev/null || sudo iptables -I INPUT 6 -p tcp --dport 443 -j ACCEPT
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y netfilter-persistent iptables-persistent || true
sudo netfilter-persistent save || true

echo ""
echo "=================================================================="
echo " Provision selesai (Caddy + PHP 8.4 FPM)."
echo " Lanjut (lihat deploy/README-DEPLOY.md):"
echo "  4) Upload kode ke /var/www/javacore + database.sqlite (data lama)"
echo "  5) Buat .env dari deploy/.env.production.example (isi APP_KEY lama + APP_URL)"
echo "  6) bash deploy/deploy.sh"
echo "  7) Pasang Caddyfile (ganti domain) → sudo systemctl reload caddy"
echo " WAJIB: buka Ingress 80 & 443 di OCI Console (VCN → Security List)."
echo "=================================================================="
