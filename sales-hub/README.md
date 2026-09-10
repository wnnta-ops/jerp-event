# Java Sales Hub — Internal Sales System

Internal web app for a residential property developer's sales division. Replaces
a manual multi-workbook Excel process. Built to match the approved prototype
(`Sales Hub - Internal System.html`) and the spec in `IMPLEMENTATION_BRIEF.md`.

## Core principle — event-based single source of truth

There are only **two core records** — **Deal (Customer)** and **Unit** — plus
reference tables and an **append-only `PipelineEvent` ledger**. Sales input an
event once; **every** report, KPI and count (daily/monthly/yearly, SPR-YTD, sisa
stock, hold, waiting list, run-rate, per-team, per-type, weekly trend) is
**derived** by query in `src/lib/derivations.ts`. Nothing is typed twice.

- **Koordinator is never typed** — resolved from the deal's `bendera` via the
  effective-dated `RotationAssignment` table at `tanggalWi`.
- **Unit status follows the deal** (UTJ→Proses, Akad/Realisasi→Realisasi/Cash
  Lunas, Batal→Open Stock), with a manual override flag.

## Stack

Next.js 15 (App Router) · React 19 · Tailwind · PostgreSQL + Prisma ·
NextAuth (credentials, role-based) · `@resvg/resvg-js` for server-side SVG→PNG.
Indonesian UI, timezone **WIB (UTC+7)**. Mobile-responsive (sidebar collapses).

## Setup

```bash
# 1. PostgreSQL — set the connection string in .env (DATABASE_URL)
createdb java_sales_hub

# 2. Install
npm install

# 3. Schema + seed (imports from the two source .xlsx files)
npm run db:push
npm run db:seed        # 1,080 units + 973 historical deals + 3,694 events

# 4. Run
npm run dev            # http://localhost:3000
```

**Demo logins** (password: `password`):

| Role | Email |
|---|---|
| Management | `admin@javasaleshub.test` |
| Sales / Koordinator | `sales@javasaleshub.test` |
| Collection | `collection@javasaleshub.test` |

## Roles & ownership (§6)

- **Sales / Koordinator** own pipeline stages **0–4** (WI → Follow-Up → NUP →
  UTJ → **SPR**). Edit rotation. Input UTJ on Open-Stock units, manual override.
- **Collection** own stages **5–7** (ACC → Akad → Realisasi) + Batal/refund.
- **Management** can do everything; sets targets & master data.

Enforced centrally in `src/lib/rbac.ts` and in every API route.

## Modules

| Route | Feature |
|---|---|
| `/` | Dashboard — KPI tiles, clickable flow bar, hero, hot leads |
| `/input` | Input Harian — quick WI entry, today's derived tally |
| `/pipeline` | Customer Pipeline — kanban + table + journey stepper, role-gated advance, follow-ups |
| `/daily` | Daily Report — server-side PNG for WA + per-day history picker |
| `/monthly` | Monthly / Yearly — derived charts + per-team + stock per cluster×phase |
| `/stock` | Stock Unit — filters/search, ＋UTJ, manual override |
| `/rotasi` | Rotasi & Bendera — effective-dated coordinator↔bendera (sales edit, others view) |
| `/collection` | Collection — queue, ACC→Akad→Realisasi, Batal + refund |

## Data seeding

`scripts/extract_seed.py` (openpyxl) reads the two workbooks and writes
`prisma/seed-data/*.json`; `prisma/seed.ts` loads them. For every sold/in-progress
unit it synthesizes a historical Deal + PipelineEvents so reports reflect real
history. Re-run extraction with `python3 scripts/extract_seed.py`.

Enums seeded from the **actual** sheet data (e.g. Cluster 2 type *Mahoni*, not
the brief's "Ruha"; status `HOLD UNIT`→`HOLD`).

## Daily Report image (§8)

Portrait 760×1080 PNG at `/api/report/daily?date=YYYY-MM-DD[&download=1]`. Orange
header (`#f0a94e`, per decision). All sections driven by the selected day's
derived data. `?download=1` forces attachment for WhatsApp sharing.

## Known scope notes / phase 2

- **Per-team splits**: the source sheets don't map sales→team, so `Sales.teamId`
  is null after seed and team figures bucket into "Tanpa Tim". Assign teams via
  master data to populate the per-team report.
- Seeded history covers **Jan–Jun 2026**, so "bulan ini" (current month) is empty
  until new events are entered — use the Daily Report date picker to view any past
  day (e.g. a June day shows populated numbers).
- **Phase 2** (per brief §11): WhatsApp Cloud API auto-send, refund workflow UI,
  notifications, PDF/Excel export, audit-trail UI, per-sales cuts.
```
