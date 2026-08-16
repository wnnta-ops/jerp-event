#!/usr/bin/env bash
# Deploy/refresh J-ERP (FO) + ipro-fo (BO/Bankloan) — 2 app, 1 MySQL, SSO.
# Pakai SETELAH .env kedua app diisi (lihat marger/DEPLOY.md).
#   bash marger/deploy.sh              # deploy pertama (dengan --seed)
#   SEED=0 bash marger/deploy.sh       # deploy ulang (tanpa seed, jaga data)
set -euo pipefail

# ── Sesuaikan path bila perlu ────────────────────────────────────────────────
JERP_DIR="${JERP_DIR:-$(pwd)}"                            # folder J-ERP (ada docker-compose.yml)
IPRO_DIR="${IPRO_DIR:-$JERP_DIR/marger/ipro-fo_1/ipro-fo}"
# prod.yml PALING AKHIR: menutup port Redis/Meili/Reverb/MinIO/Mailpit yang
# diterbitkan setelan dev ke 0.0.0.0. Urutan -f menentukan, yang belakangan menang.
IPRO_FILES="-f docker-compose.yml -f docker-compose.override.yml -f docker-compose.shared.yml -f docker-compose.prod.yml"
# J-ERP: base + prod override → mysql/redis/meili TIDAK di-expose ke host (aman), nginx tetap 8081.
# Override utk lokal: JERP_COMPOSE_FILE=docker-compose.yml:docker-compose.override.yml
JERP_COMPOSE_FILE="${JERP_COMPOSE_FILE:-docker-compose.yml:docker-compose.prod.yml}"
SEED="${SEED:-1}"                                        # 1 = jalankan seeder (deploy pertama)

mysql_root() { docker exec jerp-mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e \"$1\""; }

echo "==> 1/11 Network shared-db";        docker network create shared-db 2>/dev/null || echo "   (sudah ada)"
echo "==> 2/11 Up J-ERP";                 ( cd "$JERP_DIR" && COMPOSE_FILE="$JERP_COMPOSE_FILE" docker compose up -d --build )
echo "==> 3/11 jerp-mysql -> shared-db";  docker network connect shared-db jerp-mysql 2>/dev/null || echo "   (sudah tersambung)"
echo "==> 4/11 Tunggu jerp-mysql sehat";  until docker exec jerp-mysql sh -c 'mysqladmin ping -h localhost -uroot -p"$MYSQL_ROOT_PASSWORD"' >/dev/null 2>&1; do sleep 2; done
echo "==> 5/11 DB ipro_bo + grant"
out=$(mysql_root "CREATE DATABASE IF NOT EXISTS ipro_bo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON ipro_bo.* TO 'jerp'@'%'; GRANT SELECT ON jerp.* TO 'jerp'@'%'; FLUSH PRIVILEGES;" 2>&1) || { echo "$out"; echo "   GAGAL buat DB ipro_bo / grant — cek MYSQL_ROOT_PASSWORD & kesiapan jerp-mysql."; exit 1; }
echo "$out" | grep -vi warning || true

echo "==> 6/11 Up ipro-fo";               ( cd "$IPRO_DIR" && docker compose $IPRO_FILES up -d --build app nginx redis )
echo "==> 6b   Tunggu ipro-fo app bisa konek ipro_bo (cegah migrate gagal cold-boot)"
tries=0
until ( cd "$IPRO_DIR" && docker compose $IPRO_FILES exec -T app php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:3306).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' >/dev/null 2>&1 ); do
  tries=$((tries + 1)); [ "$tries" -ge 30 ] && { echo "   GAGAL: ipro-fo app tak bisa konek DB setelah 30x — cek kredensial & shared-db."; exit 1; }
  echo "   tunggu DB ipro_bo... ($tries)"; sleep 3
done
echo "==> 7/11 composer ipro-fo";         ( cd "$IPRO_DIR" && docker compose $IPRO_FILES exec -T app composer install --no-dev --no-interaction --no-progress )
echo "==> 8/11 migrate ipro-fo (seed=$SEED)"
if [ "$SEED" = "1" ]; then ( cd "$IPRO_DIR" && docker compose $IPRO_FILES exec -T app php artisan migrate --seed --force )
else                       ( cd "$IPRO_DIR" && docker compose $IPRO_FILES exec -T app php artisan migrate --force ); fi
echo "==> 9/11 Build aset ipro-fo (fix rollup musl)"
( cd "$IPRO_DIR" && docker compose $IPRO_FILES exec -T app sh -c "(npm ci --no-audit --no-fund || (echo '   npm ci gagal (mungkin bug rollup musl) → reinstall bersih'; rm -rf node_modules package-lock.json && npm install --legacy-peer-deps --no-audit --no-fund)) && npm run build && php artisan config:cache" )

echo "==> 10/11 Migrate J-ERP";           docker exec jerp-app php artisan migrate --force
echo "==> 11/11 Backfill data FO -> BO";  docker exec jerp-app php artisan ipro:backfill all

docker restart ipro-fo-nginx >/dev/null 2>&1 || true     # re-resolve upstream app
echo "==> Mobile Approver (opsional): set mobile/approval_flow/src/config.ts ->"
echo "    API_BASE_URL='<FO_URL>/api/mobile', lalu: docker compose -f docker-compose.mobile.yml up -d"
echo "==> SELESAI. Uji SSO (hub -> Back Office). Start harian SEMUA (FO+BO+Mobile): bash marger/up.sh"
