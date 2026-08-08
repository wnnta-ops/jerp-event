# Deploy — Legal Module (Vercel + Postgres)

The app is build-validated (`npm run build` passes) and deploy-ready. Two
external inputs are needed: a Postgres database and a Vercel project.

## 1. Postgres database (Neon)
1. https://neon.tech → create a project → copy the connection string
   (`postgresql://…?sslmode=require`).

## 2. Point the schema at Postgres + load data (run locally, once)
1. In `prisma/schema.prisma`, set the datasource provider to `postgresql`.
2. `DATABASE_URL="postgresql://…" npx prisma db push`
3. `DATABASE_URL="postgresql://…" npm run deploy:seed`
   (seeds users + imports AJB + PT entities + regenerates sample placeholders + role accounts)
4. Commit the regenerated `public/sample/` and push.

## 3. Vercel
1. https://vercel.com → Add New → Project → import `mup-legal-module` (auto-detects Next.js).
2. Environment Variables:
   - `DATABASE_URL` = the Neon connection string
   - `AUTH_SECRET`  = output of `openssl rand -hex 32`
   - `REQUEST_DOCS_DIR` = `/tmp/request-docs`
   - (optional) `GOOGLE_SHEET_CSV_URL`, `GOOGLE_SHEET_TITLE`
3. Deploy → you get a `*.vercel.app` URL. Push-to-deploy is automatic after this.

## Demo accounts (password `Legal#2026`)
`legal.pic@` · `head.legal@` · `gm@` · `direktur@` · `pemohon@mup.co.id`

## Known demo limitations
- Fulfillment uploads write to `/tmp` on serverless → not permanent (swap to Blob/S3 for that).
- Sample documents only (placeholder PDFs); real content never uploaded.
