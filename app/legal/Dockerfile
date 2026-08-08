# Legal (Next.js 14 + Prisma/Postgres) — dijalankan di balik jerp-nginx path /legal.
# openssl + libc6-compat WAJIB: Prisma 5 di Alpine gagal deteksi libssl tanpa ini.
FROM node:20-alpine AS deps
WORKDIR /app
RUN apk add --no-cache openssl libc6-compat
COPY package.json package-lock.json ./
COPY prisma ./prisma
RUN npm ci --no-audit --no-fund

FROM node:20-alpine AS build
WORKDIR /app
RUN apk add --no-cache openssl libc6-compat
COPY --from=deps /app/node_modules ./node_modules
COPY . .
# Build tidak menyentuh DB (semua halaman dynamic — pakai cookies()).
RUN npx prisma generate && npm run build

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
