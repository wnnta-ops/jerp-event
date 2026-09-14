#!/bin/bash
# One-time setup of the PRODUCTION database (schema + seed data).
# Run this ONCE after creating your hosted Postgres, before/after first deploy.
#
# Usage:
#   ./scripts/setup-prod-db.sh "postgresql://USER:PASS@HOST/DB?sslmode=require"
#
# It applies the schema (migrate deploy) then loads the seed data
# (1,080 units + historical deals + 149 batal records).
set -e

URL="$1"
if [ -z "$URL" ]; then
  echo "Usage: ./scripts/setup-prod-db.sh \"<DATABASE_URL>\""
  exit 1
fi

echo "→ Applying schema (prisma migrate deploy)…"
DATABASE_URL="$URL" npx prisma migrate deploy

echo "→ Seeding data…"
DATABASE_URL="$URL" npx tsx prisma/seed.ts

echo "✅ Production DB ready. Demo logins: admin@ / sales@ / collection@ javasaleshub.test (password: password)"
