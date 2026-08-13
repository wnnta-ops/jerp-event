# Legal (Next.js 14 + Prisma/Postgres) — dijalankan di balik jerp-nginx path /legal.
# openssl + libc6-compat WAJIB: Prisma 5 di Alpine gagal deteksi libssl tanpa ini.
FROM node:20-alpine AS deps
WORKDIR /app
RUN apk add --no-cache openssl libc6-compat
COPY package.json package-lock.json ./
COPY prisma ./prisma
# COBA-ULANG WAJIB: postinstall menjalankan `prisma generate` yang mengunduh engine
# dari binaries.prisma.sh — CDN milik Prisma sendiri, di luar kendali kita.
# Dari sebagian server unduhan itu sporadis
# ETIMEDOUT — terbukti: permintaan yang sama gagal 2x lalu berhasil tanpa ada yang
# diubah. Pengunduh Prisma TIDAK punya coba-ulang, jadi satu kegagalan mematikan
# seluruh build. Cache npm bertahan antar percobaan dalam layer yang sama sehingga
# percobaan berikutnya jauh lebih cepat.
RUN for i in 1 2 3 4 5; do \
      npm ci --no-audit --no-fund && exit 0; \
      echo "[coba-ulang] npm ci gagal (percobaan \$i/5) — ulang dalam 10 detik"; \
      sleep 10; \
    done; echo "[coba-ulang] npm ci tetap gagal setelah 5 percobaan"; exit 1

FROM node:20-alpine AS build
WORKDIR /app
RUN apk add --no-cache openssl libc6-compat
COPY --from=deps /app/node_modules ./node_modules
COPY . .
# Build tidak menyentuh DB (semua halaman dynamic — pakai cookies()).
# prisma generate diulang dengan alasan yang sama seperti di tahap deps.
RUN (for i in 1 2 3; do \
       npx prisma generate && exit 0; \
       echo "[coba-ulang] prisma generate gagal (percobaan \$i/3) — ulang dalam 10 detik"; \
       sleep 10; \
     done; exit 1) && npm run build

FROM node:20-alpine AS runtime
WORKDIR /app
RUN apk add --no-cache openssl libc6-compat
ENV NODE_ENV=production
COPY --from=build /app/.next ./.next
COPY --from=build /app/node_modules ./node_modules
COPY --from=build /app/public ./public
COPY --from=build /app/prisma ./prisma
COPY --from=build /app/scripts ./scripts
COPY --from=build /app/package.json ./package.json
COPY --from=build /app/next.config.mjs ./next.config.mjs
EXPOSE 3000
# db push idempoten (sinkronkan schema) lalu start. Seed dijalankan manual sekali:
#   docker exec jerp-legal npm run db:seed
CMD ["sh", "-c", "npx prisma db push --skip-generate && npx next start -p 3000"]
