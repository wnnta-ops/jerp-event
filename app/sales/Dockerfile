# Sales — Java Sales Hub (Next.js 15 + NextAuth + Prisma/Postgres),
# dijalankan di balik jerp-nginx path /sales.
FROM node:20-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
COPY prisma ./prisma
RUN npm ci --no-audit --no-fund

FROM node:20-alpine AS build
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npx prisma generate && npm run build

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
