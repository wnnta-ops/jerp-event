# Deploy Java Sales Hub to the cloud (public domain, accessible anywhere)

The app is a Next.js app + PostgreSQL. Recommended host: **Vercel** (app) + **Neon**
(serverless Postgres). Both have free tiers and give you an `https://…` domain.
Total time ≈ 15 minutes. You only need a browser — no CLI.

The code is already pushed to GitHub (private), so Vercel can import it directly.

---

## Step 1 — Create the database (Neon)

1. Go to **https://neon.tech** → sign up (use "Continue with GitHub").
2. Create a project (region: pick Singapore/closest). It creates a database.
3. On the project dashboard, copy the **connection string** — it looks like:
   `postgresql://user:pass@ep-xxx.ap-southeast-1.aws.neon.tech/neondb?sslmode=require`
   Keep this; it's your `DATABASE_URL`.

## Step 2 — Load schema + data into the database (once, from your Mac)

In Terminal:

```bash
cd "/Users/azizawiennetaputri/Desktop/Claude Sales"
./scripts/setup-prod-db.sh "PASTE_YOUR_NEON_URL_HERE"
```

This creates all tables and seeds the data (units, deals, 149 batal records).
You should see "✅ Production DB ready".

## Step 3 — Deploy the app (Vercel)

1. Go to **https://vercel.com** → sign up with **GitHub**.
2. **Add New → Project** → import the repo **`java-sales-hub`**.
3. Before clicking Deploy, open **Environment Variables** and add three:

   | Name | Value |
   |------|-------|
   | `DATABASE_URL` | your Neon connection string (from Step 1) |
   | `NEXTAUTH_SECRET` | run `openssl rand -base64 32` in Terminal and paste the output |
   | `NEXTAUTH_URL` | leave for now, or set after first deploy (see Step 4) |

4. Click **Deploy**. Wait ~2 min. You'll get a URL like `https://java-sales-hub-xxx.vercel.app`.

## Step 4 — Point auth at the real domain

1. Copy your Vercel URL (e.g. `https://java-sales-hub-xxx.vercel.app`).
2. Vercel → your project → **Settings → Environment Variables** → set
   `NEXTAUTH_URL` = that URL (no trailing slash).
3. **Deployments → ⋯ → Redeploy** (so the new value takes effect).

Now open the URL from **any device, anywhere**. Log in with:

- `admin@javasaleshub.test` / `password` (Management)
- `sales@javasaleshub.test` / `password` (Sales)
- `collection@javasaleshub.test` / `password` (Collection)

## Step 5 (optional) — Custom domain

1. Buy a domain (Namecheap, Cloudflare, Niagahoster, etc.).
2. Vercel → project → **Settings → Domains** → add your domain and follow the DNS
   instructions (add the CNAME/A record at your registrar).
3. Update `NEXTAUTH_URL` to `https://yourdomain.com` and redeploy.

---

## Notes / gotchas

- **Change the demo passwords** before sharing widely — everyone currently uses
  `password`. (Ask and I'll add a proper user-management screen.)
- The seed data contains **real customer names** from your Excel. The GitHub repo is
  **private**; keep it private.
- Report images (PNG) use `@resvg/resvg-js`, already configured for serverless via
  `serverExternalPackages` in `next.config.mjs`.
- To reseed prod later: rerun `./scripts/setup-prod-db.sh "<url>"` (it wipes & reloads).
- Schema changes: commit, then run `DATABASE_URL="<url>" npm run db:deploy`.
