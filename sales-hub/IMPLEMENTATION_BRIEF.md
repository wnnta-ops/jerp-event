# Sales Hub — Implementation Brief (for Claude Code)

> Internal sales system for a residential property developer. Replaces a manual Excel workflow.
> This brief is the handoff spec. The clickable prototype `Sales Hub - Internal System.html` (same folder) is the **approved UX/flow reference** — build the real app to match its structure, modules, and soft/orange theme.

---

## 1. Context & goal

The sales division sells houses across several **clusters** (Cluster 2, 3, 5), each built in **phases (THP)**, each phase containing house **types**, each type having individual **units** (block + number).

**Today (Excel):** two workbooks —
- `Daily Report ….xlsx`: 180+ tabs (one per day) + recap sheets (WI recap, UTJ recap, batal recap, coordinator↔agent map, trend, targets).
- `MASTER DATA SALES ….xlsx`: unit-level stock per cluster (SUMMARY, CLUSTER 2/3/5, BATAL/REFUND, waiting-list sheet).

**Core problem — double input.** One event (e.g. a UTJ) is re-typed into 6–8 places: the day's tab (counts by cluster.phase + WI/follow-up detail + trend-per-type + closing-per-team + weekly recap), the UTJ recap sheet, the master cluster sheet, the waiting-list sheet, and — if cancelled — the cancellation detail + batal recap + refund sheet. A brand-new tab is created every day, and monthly/yearly summaries are compiled by hand.

**Goal.** A web system where sales **input once** (like the Excel), and the system auto-generates:
1. **Daily report as a downloadable IMAGE (PNG)** for the WhatsApp group (recap divisi + progress target).
2. Monthly & yearly reports.
3. A full **per-customer pipeline** (sales fill the early stages; Collection continues).
No re-typing; no manual tabs.

---

## 2. Core design principle (read this first)

**Event-based single source of truth.** There are only **two core records**:
- **Customer / Deal** — one person's journey through the pipeline.
- **Unit** — one house and its status.

Plus **reference tables** (clusters/phases/types, sales, coordinators↔bendera, targets, banks, promos).

Everything else — every count, every report, every KPI (daily numbers per cluster.phase / per type / per team, monthly/yearly, SPR-to-date, sisa stock, hold, waiting list, sales potensial, run-rate, target %) — is a **query/aggregation**, never a stored duplicate. Sales log an event on a Customer or Unit; reports are computed. This is the single most important thing to preserve.

---

## 3. Domain glossary (Bahasa Indonesia terms)

| Term | Meaning |
|---|---|
| WI | Walk-In — a prospect visits/contacts. Channels: **Site**, **Pameran/OT** (exhibition/open table), **Janjian** (by appointment) |
| Follow-up | Following up a lead; carries a lead status (Hot/Warm/Cold) |
| NUP | Nomor Urut Pemesanan — booking-queue number taken before UTJ |
| UTJ | Uang Tanda Jadi — booking deposit; **locks a unit** |
| SPR | Surat Pemesanan Rumah — purchase order document |
| ACC | Bank/KPR approval |
| Akad | Akad kredit — mortgage signing |
| Realisasi | Loan disbursed / deal completed |
| Batal | Cancellation (of UTJ / SPR / Waiting List), with a reason + possible refund |
| KOL 2 / KOL 5 | Bank collectibility score (credit history) — common cancel reason |
| Waiting List | UTJ taken but no unit assigned yet |
| Cluster → Phase (THP) → Type → Block/No | Unit hierarchy. E.g. `Cluster 3 · Phase IV · New Camar · JRK3 IV No.5` |
| Bendera | The external agent/agency brand (Brighton, Ray White, Capital, …) that brought the lead |
| Koordinator | Internal coordinator who "owns" a set of benderas (rotation) |
| Cara bayar | KPR or Cash |
| Promo | Free DP, DP 3x, etc. |
| Realisasi/Cash Lunas/Proses/Hold/Open Stock | Unit statuses |

---

## 4. Data model

### 4.1 Core entities

**Customer / Deal**
| Field | Type / values | Notes |
|---|---|---|
| id | uuid | |
| nama | string | customer name |
| channel | enum: Site, Pameran, Janjian | WI channel |
| pameran_lokasi | string (nullable) | if channel = Pameran |
| sumber_informasi | string | lead source (referral, ad, WI lewat, …) |
| bendera | fk → Agency | agent brand |
| sales | fk → Sales | |
| koordinator | fk → Coordinator | **derived** from bendera via rotation (see 6) |
| cluster / phase / type | fk refs | unit interest (before UTJ), actual after |
| status_lead | enum: Hot, Warm, Cold | see open decision on label |
| stage | enum (see §5) | current pipeline stage |
| tanggal_wi | date | |
| fu_count | int | "FU ke berapa" |
| follow_ups | list of {date, note, status} | history |
| unit_id | fk → Unit (nullable) | set at UTJ |
| cara_bayar | enum: KPR, Cash | |
| promo | enum: Free DP, DP 3x, none | |
| bank | string (nullable) | e.g. BTN SBD, BSN Gresik |
| tgl_nup / tgl_utj / tgl_spr / tgl_acc / tgl_akad / tgl_realisasi | date (nullable) | stage timestamps |
| waiting_list | bool | UTJ without assigned unit |
| batal | bool | |
| batal_kategori | enum: UTJ, SPR, Waiting List | |
| batal_alasan | enum: KOL 2, KOL 5, Reject Bank, Batal Sepihak, Masalah Keluarga, Skema Bayar, Other | |
| batal_tanggal | date | |
| refund | {status, amount} | |
| catatan | text | |

**Unit**
| Field | Type / values | Notes |
|---|---|---|
| id | uuid | |
| unit_code | string | e.g. `JRK3 IV No.5` (display) |
| cluster / phase / type / block / no | refs / strings | |
| status | enum: Open Stock, Hold, Proses, Realisasi, Cash Lunas, Batal | **derived** from linked deal stage (see 9) + manual override |
| current_deal_id | fk (nullable) | |
| tgl_spr_cetak / tgl_utj / tgl_akad | date (nullable) | |
| harga | number (optional) | not in current Excel scope; confirm |

### 4.2 Reference tables
- **Cluster** { name, phases[] } — Cluster 2 (I, II), Cluster 3 (I–IV), Cluster 5 (I, II).
- **Type** { cluster, name } — C2: Angsana, Akasia, Cendana, Ruha · C3: New Cica, New Cica Hook, New Camar, New Caladi, Cica, Camar, Camar Hook, Caladi · C5: Avelin, Avero, Avero Hook, Aventis.
- **Sales** { name, team } — team ∈ {Team Putri, Team Naufal}.
- **Coordinator** { name } — e.g. Putri, Naufal, Adam, Adi, Ari, Agung, Sigit, Famar.
- **Agency / Bendera** { name }.
- **RotationAssignment** { bendera_id, coordinator_id, effective_from, effective_to } — **effective-dated history** so past reports resolve the coordinator that was valid at the time. (Confirmed requirement.)
- **Target** { scope: annual | team | week, period, value, metric: unit | NUP }. Known: Target 2026 = **798 units**; monthly target Juni = 40; per-team weekly targets (Putri/Naufal).
- **Bank**, **Promo** — enums/lookups.

> **Seed data:** import clusters/phases/types/units/rotation from the two `.xlsx` files in this folder (SUMMARY + CLUSTER sheets for stock; `AGENT PER KOOR NEW` for rotation).

---

## 5. Pipeline (8 stages) + ownership

`0 WI → 1 Follow-Up → 2 NUP → 3 UTJ → 4 SPR ‖ 5 ACC Bank → 6 Akad → 7 Realisasi`

- **Sales / Koordinator own stages 0–4** (up to and including **SPR** — confirmed: "sales boleh tandai SPR").
- **Collection owns stages 5–7** (ACC → Akad → Realisasi).
- **Branches:** *Waiting List* = UTJ reached but no unit assigned. *Batal* = cancel at any stage (record kategori + alasan + refund; unit returns to Open Stock).

**Transition side-effects**
- Enter **UTJ** with a chosen unit → unit.status = Proses (or Hold), unit.current_deal_id set, unit removed from available stock.
- **Batal** → unit.status = Open Stock, unassign deal, log batal + refund.
- **Realisasi** → unit.status = Realisasi (or Cash Lunas if cash).

---

## 6. Roles & permissions

| Capability | Sales / Koordinator | Collection | Management / Admin |
|---|---|---|---|
| Input WI / Follow-up / NUP / UTJ / **SPR** | ✅ | — | ✅ |
| Advance ACC / Akad / Realisasi | — | ✅ | ✅ |
| Batal + refund | — (request) | ✅ | ✅ |
| Stock: input UTJ on Open Stock, manual status override | ✅ | view | ✅ |
| Rotasi koordinator ↔ bendera | ✅ **edit** | 👁 **view-only** | ✅ |
| Set targets, manage reference/master data, users | — | — | ✅ |
| View all reports / dashboards | ✅ | ✅ | ✅ |

Auto-derivation: **koordinator is never typed** — it's resolved from the deal's bendera via the rotation table (effective-dated by `tanggal_wi`).

---

## 7. Modules (map to the 7 requested features)

1. **Dashboard** — KPI tiles: Target 2026 (798) + SPR YTD %, UTJ net this month vs target, run-rate needed/day, sisa stock, hold, waiting list, sales potensial, sisa hari. Hero banner (greeting + realistic one-line encouragement). A **clickable flow bar** (stages 1–5 deep-link to their input screen; 6–8 labelled "tim Collection").
2. **Input Harian** — quick daily entry (＋WI with channel, ＋NUP, ＋UTJ, ＋SPR, ＋Batal); today's tally. Everything flows to Pipeline + Daily Report + Summary. *(Feature: sales fill what they used to fill in Excel.)*
3. **Customer Pipeline** *(feat. 4, 5, 7)* — kanban + table of all customers across the 8 stages; per-customer journey with a stepper; role-gated stage advancement; search bar.
4. **Daily Report** *(feat. 1)* — see §8. Auto from the day's events; **download PNG for WA**; date picker to view/download **history per day**.
5. **Monthly / Yearly Report** *(feat. 2)* — auto aggregates; UTJ net per month/year; per-team; stock per cluster×phase; Target 2026 progress; export.
6. **Stock Unit** *(feat. 3)* — units by cluster · phase · type · block; filters + search; status KPIs; ＋UTJ on Open Stock; manual **Update** per unit.
7. **Rotasi & Bendera** *(feat. 6)* — coordinator ↔ bendera map; **sales edit, collection view-only**; changes stored with effective dates.
8. **Collection** *(feat. 7)* — queue from UTJ/SPR; Collection updates ACC → Akad → Realisasi (or Batal + refund); stepper per unit; search bar.

---

## 8. Daily Report image spec (headline feature)

**What:** a single shareable **PNG infographic** (portrait, ~760×1080) that sales send to the WhatsApp group. Content = "recap divisi + progress target" (confirmed choice), for the whole division (confirmed scope).

**Sections (top→bottom):**
1. Header — "DAILY REPORT SALES", Divisi Sales · date (+ "arsip" tag if a past day).
2. **HARI INI** — 5 tiles: WI, NUP, UTJ, SPR, Batal.
3. **Progress Target** — bar: month target (net/target, %) + bar: annual Target 2026 (SPR YTD / 798, %).
4. **UTJ net per team** — Putri, Naufal vs target.
5. **UTJ per type (month)** — horizontal bars (e.g. New Camar, New Cica, New Caladi…).
6. **Trend UTJ mingguan (net)** — W1–W5 columns.
7. **Sisa stock** — per cluster.
8. Footer — Hold · Waiting List · Sales Potensial + timestamp.

**Generation:** the prototype builds an SVG and rasterizes it client-side (SVG → `<canvas>` → `toBlob` PNG) — works offline, no deps. For production, prefer **server-side rendering** (e.g. Satori/`@vercel/og`, or headless Chrome/Puppeteer) so the image is consistent and can be auto-attached to WhatsApp. Data comes from the selected date's aggregated events.

**Sharing:** MVP = download + user shares to WA manually. v2 = auto-send via **WhatsApp Cloud API** (Meta) or Twilio to the group.

---

## 9. Auto-derivation rules (the "no double input" logic)

- `koordinator = rotation.lookup(bendera, at=tanggal_wi)` (effective-dated).
- `unit.status` follows the linked deal's stage (UTJ→Proses, Akad/Realisasi→Realisasi/Cash Lunas, Batal→Open Stock); manual override allowed.
- Daily counts = aggregate events by `date × cluster.phase × type × team × stage`.
- `UTJ net = UTJ gross − batal`; `% = net / target`.
- `SPR today` = cumulative count of SPR to date.
- `sisa stock` (per cluster×phase) = `total − (realisasi + cash_lunas + proses + hold)`.
- `run-rate needed = remaining_annual_target / remaining_working_days`.
- `sales potensial` = count of leads with status_lead = Hot.
- `waiting list` = deals at UTJ with no assigned unit.
- Monthly/yearly = same aggregation over wider date ranges. **Nothing above is stored twice** — all computed from Customer + Unit + events.

---

## 10. Non-functional & recommended stack (confirm)

- **Frontend:** React (Next.js) + Tailwind — matches the prototype; **mobile-responsive** (sales work from phones). Soft pastel theme; **main color = warm orange `#f0a94e`**, accents teal/coral/purple retained.
- **Backend:** Next.js API routes or NestJS; **PostgreSQL** via Prisma.
- **Auth:** role-based (Sales / Collection / Management) — NextAuth/JWT.
- **Report image:** server-side SVG→PNG (Satori) for reliability + WA auto-send.
- **WhatsApp:** WhatsApp Cloud API (phase 2); manual share (phase 1).
- **i18n:** Indonesian UI; keep domain terms verbatim. **Timezone: WIB (UTC+7).**
- **Audit log** on stage changes and cancellations (who/when).

---

## 11. Suggested phasing

**MVP (phase 1)**
- Reference/master data + import from the two Excel files.
- Customer + Unit models; Input Harian; Customer Pipeline (with role gating); Stock; Rotasi (with history); Collection.
- Daily Report with **download PNG** + per-day history; Dashboard KPIs; Monthly/Yearly view.

**Phase 2**
- Auto WhatsApp send; refund workflow; notifications (e.g. "customer datang 5 menit lagi"); exports (PDF/Excel); audit trail UI; per-team/per-sales report cuts.

---

## 12. Open decisions (please confirm before build)

1. **Label for Hot/Warm/Cold** — prototype uses "Status Lead". Team's actual term? (Status Lead / Kategori / Minat / Prioritas)
2. **Daily report header color** — currently teal; make it orange to match the new main color?
3. **WhatsApp send** — manual download first, or WA Cloud API from day one?
4. **Tech stack / hosting** — confirm the recommended stack above, or existing team preference.
5. **Unit price** — track harga per unit? (not in current Excel scope)
6. **Single project only?** — clusters 2/3/5 suggest one development; confirm no multi-project need.
7. **Confirm SPR ownership** — sales mark SPR, Collection takes ACC onward (assumed from our discussion).

---

## 13. Reference artifacts (in this folder)
- `Sales Hub - Internal System.html` — clickable UX/flow prototype (source of truth for layout, modules, theme, and the daily-report image design).
- `Daily Report ….xlsx`, `MASTER DATA SALES ….xlsx` — current data; use for seeding and to validate field lists/enums.

_Build to match the prototype's UX; implement the data model and auto-derivation above so every fact is entered once._
