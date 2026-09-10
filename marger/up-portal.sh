#!/usr/bin/env bash
# Update app portal Legal & Sales (Next.js + Prisma/Postgres) — build + up + restart nginx.
# git pull LAKUKAN SENDIRI dulu. FO/BO & database (jerp-mysql, ipro_bo) TIDAK disentuh.
# Jalankan:  bash marger/up-portal.sh
#
# WAJIB di .env root sebelum jalan (lihat .env.example):
#   PORTAL_SSO_SECRET, PG_USER, PG_PASSWORD,
#   LEGAL_AUTH_SECRET, LEGAL_EXTERNAL_APPROVAL_SECRET,
#   SALES_NEXTAUTH_SECRET, SALES_NEXTAUTH_URL(=https://<domain>/sales/api/auth)
#
# CATATAN: skrip ini TIDAK men-seed DB dan TIDAK membuat akun. Keduanya dijalankan
# manual, dan sengaja tidak dicetak tiap deploy — perintah seed MENIMPA data, dan
# menampilkannya berulang di sebelah perintah yang aman mengundang salah salin.
#
#   Seed DB — SEKALI saja saat pertama, MENIMPA data:
#     docker exec jerp-legal npm run db:seed
#     docker exec jerp-sales npx tsx prisma/seed.ts
#
#   Akun admin IT — upsert, aman diulang, pilih kata sandi sendiri:
#     docker exec jerp-legal node scripts/buat-admin.mjs <email> '<sandi>' 'IT Sysadmin'
#     docker exec jerp-sales node scripts/buat-admin.mjs <email> '<sandi>' 'IT Sysadmin'
set -euo pipefail
JERP_DIR="${JERP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$JERP_DIR"

echo "==> 1/4 build image Legal & Sales (Next.js)"
docker compose build legal sales

echo "==> 2/4 up postgres + legal + sales (mysql/ipro_bo TIDAK disentuh)"
docker compose up -d postgres legal sales

echo "==> 3/4 sinkron schema Prisma (idempoten; enum/kolom baru masuk saat start)"
# Container start sudah menjalankan `prisma db push`, tapi paksa sekali lagi supaya
# perubahan schema (mis. enum Role SYSADMIN) pasti terterap walau start cepat.
docker exec jerp-legal npx prisma db push --skip-generate >/dev/null 2>&1 || true
docker exec jerp-sales npx prisma db push --skip-generate >/dev/null 2>&1 || true

echo "==> 4/4 restart nginx (re-resolve upstream legal/sales — hindari 502/404)"
docker restart jerp-nginx >/dev/null 2>&1 || true

echo "==> PORTAL SELESAI.  Legal -> /legal   Sales -> /sales   (via jerp-nginx)"
