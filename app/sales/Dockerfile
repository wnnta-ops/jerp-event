# Sales — Java Sales Hub (Next.js 15 + NextAuth + Prisma/Postgres),
# dijalankan di balik jerp-nginx path /sales.
FROM node:20-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
COPY prisma ./prisma
# COBA-ULANG + CERMIN: postinstall menjalankan `prisma generate`, yang menarik enam
# berkas engine dari binaries.prisma.sh sekaligus — termasuk dua .gz puluhan MB.
# Dari server produksi (umroh) unduhan itu KONSISTEN gagal ETIMEDOUT, terbukti lewat
# DEBUG=prisma:fetch-engine*: satu berkas kecil lolos lewat wget, tetapi unduhan
# serentak milik Prisma tidak pernah selesai. Pengunduh Prisma sendiri tak punya
# coba-ulang, jadi satu kegagalan mematikan seluruh build. Percobaan resmi tetap
# didahulukan; cermin hanya dipakai bila keduanya gagal.
# Cermin dipakai sebagai CADANGAN, bukan sumber utama: engine Prisma adalah kode
# yang dieksekusi, jadi sumber resmi tetap didahulukan. Bisa diganti saat build
# dengan --build-arg PRISMA_ENGINES_MIRROR=..., atau dikosongkan untuk mematikannya.
ARG PRISMA_ENGINES_MIRROR=https://registry.npmmirror.com/-/binary/prisma
RUN for i in 1 2; do \
      npm ci --no-audit --no-fund && exit 0; \
      echo "[coba-ulang] npm ci gagal - ulang dalam 10 detik"; \
      sleep 10; \
    done; \
    if [ -n "$PRISMA_ENGINES_MIRROR" ]; then \
      echo "[cermin] unduhan resmi gagal - mencoba lewat $PRISMA_ENGINES_MIRROR"; \
      PRISMA_ENGINES_MIRROR="$PRISMA_ENGINES_MIRROR" npm ci --no-audit --no-fund && exit 0; \
    fi; \
    echo "[coba-ulang] npm ci tetap gagal, termasuk lewat cermin"; exit 1

FROM node:20-alpine AS build
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
# Engine biasanya sudah ikut dari tahap deps; coba-ulang + cermin dipertahankan
# untuk berjaga bila Prisma memutuskan mengunduh ulang.
ARG PRISMA_ENGINES_MIRROR=https://registry.npmmirror.com/-/binary/prisma
RUN (npx prisma generate || \
     (sleep 10; npx prisma generate) || \
     ( [ -n "$PRISMA_ENGINES_MIRROR" ] && \
       echo "[cermin] prisma generate gagal - mencoba lewat $PRISMA_ENGINES_MIRROR" && \
       PRISMA_ENGINES_MIRROR="$PRISMA_ENGINES_MIRROR" npx prisma generate )) \
    && npm run build

FROM node:20-alpine AS runtime
WORKDIR /app
# Font sistem WAJIB: report PNG (satori/resvg loadSystemFonts) — tanpa font,
# semua TEKS di gambar Daily/Monthly report tidak tergambar (PNG "kosongan").
RUN apk add --no-cache font-inter ttf-dejavu fontconfig
ENV NODE_ENV=production
COPY --from=build /app/.next ./.next
COPY --from=build /app/node_modules ./node_modules
COPY --from=build /app/prisma ./prisma
COPY --from=build /app/package.json ./package.json
COPY --from=build /app/next.config.mjs ./next.config.mjs
EXPOSE 3000
# db push idempoten lalu start. Seed manual sekali:
#   docker exec jerp-sales npx tsx prisma/seed.ts
CMD ["sh", "-c", "npx prisma db push --skip-generate && npx next start -p 3000"]
