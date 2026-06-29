#!/usr/bin/env bash
# START semua stack J-ERP dalam SATU perintah: FO (J-ERP) + BO (ipro-fo) + Mobile Approver.
# Untuk start harian / setelah reboot server. (Deploy/migrate/seed PERTAMA: pakai deploy.sh.)
#   bash marger/up.sh            # start semua (FO + BO + Mobile)
#   MOBILE=0 bash marger/up.sh   # tanpa app mobile
#
# Catatan: TIDAK ada satu file docker-compose tunggal untuk semua, karena FO & BO
# memakai nama service generik sama (app/nginx/redis) -> bentrok bila digabung 1 project,
# dan ada langkah runtime (buat DB ipro_bo, connect network). Skrip ini menjembataninya.
set -euo pipefail

JERP_DIR="${JERP_DIR:-$(pwd)}"                            # folder J-ERP (ada docker-compose.yml)
IPRO_DIR="${IPRO_DIR:-$JERP_DIR/marger/ipro-fo_1/ipro-fo}"
IPRO_FILES="-f docker-compose.yml -f docker-compose.override.yml -f docker-compose.shared.yml"
MOBILE="${MOBILE:-1}"

echo "==> 1/5 Network shared-db";      docker network create shared-db 2>/dev/null || echo "   (sudah ada)"
echo "==> 2/5 FO (J-ERP) up";          ( cd "$JERP_DIR" && docker compose up -d )
docker network connect shared-db jerp-mysql 2>/dev/null || true
echo "==> 3/5 Tunggu jerp-mysql";      until docker exec jerp-mysql sh -c 'mysqladmin ping -h localhost -uroot -p"$MYSQL_ROOT_PASSWORD"' >/dev/null 2>&1; do sleep 2; done
docker exec jerp-mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS ipro_bo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON ipro_bo.* TO '\''jerp'\''@'\''%'\''; GRANT SELECT ON jerp.* TO '\''jerp'\''@'\''%'\''; FLUSH PRIVILEGES;"' >/dev/null 2>&1 || true
echo "==> 4/5 BO (ipro-fo) up";        ( cd "$IPRO_DIR" && docker compose $IPRO_FILES up -d app nginx redis )
docker restart ipro-fo-nginx >/dev/null 2>&1 || true     # re-resolve upstream app

if [ "$MOBILE" = "1" ]; then
  echo "==> 5/5 Mobile Approver (Expo) up"; ( cd "$JERP_DIR" && docker compose -f docker-compose.mobile.yml up -d )
  echo "   QR / URL Expo Go:  docker logs -f jerp-mobile"
else
  echo "==> 5/5 Mobile dilewati (MOBILE=0)"
fi

echo "==> SEMUA JALAN.  FO :8081  ·  BO :8090  ·  Mobile: scan QR (docker logs -f jerp-mobile)"
echo "    Stop semua:  bash marger/down.sh"
