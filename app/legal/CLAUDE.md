# CLAUDE.md — Legal module (MUP / Java Residence)

Internal Legal module for the MUP portal. Build brief: `../legal-dashboard-build-spec.md`
(features 1–4). Visual truth: `../reference-directory.html`, `../reference-timeline.html`,
`../reference-approvals.html`. UI language: **Indonesian**.

## Stack
- Next.js 14 (App Router) + TypeScript, server actions for mutations.
- Tailwind — colors are CSS variables in `app/globals.css`, copied verbatim from the
  reference mockups. Use `bg-*`/`ink-*`/`line-*` token classes (tailwind.config.ts), or
  inline `var(--color-*)`. Never hardcode hex.
- Prisma + **SQLite** (dev). SQLite has no native enums / Json / arrays, so:
  enums → `String` validated by Zod (`lib/enums.ts` + `lib/validation.ts`, the single
  source of truth), arrays → relations (`DocumentFile`, `DocumentRelation`).
  Swap `provider`/`DATABASE_URL` to Postgres before the portal merge.

## Commands
- `npm run dev` — http://localhost:3000
- `npm run db:push` / `npm run db:seed` / `npm run db:reset` (push --force-reset + seed)
- `npm run db:studio`

## Auth (dev)
No real login yet (deferred). The acting user lives in a cookie, switched via the header
role switcher. All code reads it through `lib/current-user.ts` → swap in NextAuth there.
Seeded users (one per role) in `prisma/seed.mjs`.

## Conventions
- TS strict, import alias `@/*` = project root.
- Server Components by default; `"use client"` only when interactive.
- Status carries color in the directory; type carries color in the timeline. Keep the
  shared ranking rule (overdue/today → soonest needed-by → longest waiting) in one place.
- Layout width: global shell caps at `max-w-[1320px]` with responsive gutters (`px-5 sm:px-8
  lg:px-10`), header inner aligned to the same. Wide/data pages (directory, timeline) fill it;
  forms & reading views wrap narrower — upload/alerts `max-w-[820–860px]`, request detail
  `max-w-[900px]`, queue `max-w-[1040px]` — so fields/text don't sprawl.

## Build order (spec §10) & status
1. ✅ Data model + upload form with required-field validation.
2. ✅ Directory table + tabs + filters.
3. ✅ Smart search — grouped autocomplete (Pembeli/Unit/Dokumen) + entity retrieval (pipeline order).
4. ✅ Row detail drawer — full metadata, file preview, original_location, bidirectional linked-doc chain.
5. ✅ Timeline — mini calendar + month/week + mini↔big sync + two ranked deadline boxes. `TimelineItem` table added; expiry items derived from documents at read time, work items stored.
6. ✅ Deadline alerts — `/alerts` inbox + header bell badge. `lib/alerts.ts` derives lead-time (90/60/30) renewal alerts to the PIC + escalates overdue to leadership + request-waiting alerts. `lib/notify.ts` is the single email/push seam (in-app delivery in v1).
7. ✅ Access requests — tiered sequential chain engine (`lib/access.ts`), notify, append-only audit. `AccessRequest`/`ApprovalStep`/`AuditEntry` tables. Routes: `/requests/[id]`, modal from drawer.
8. ✅ Pending-requests queue (`/requests`) — ranked, "menunggu saya" filter, mini-chain position, empty/busy + resolved history.

Chain tiering: Sales-visible → auto-approve · copy (view/download) → PIC + Head of Legal · checkout_original → PIC → Head of Legal → GM → Direktur. Authorization: only the current step's approver (or role-holder) can decide.

## v1 defaults (confirm if they should change)
File storage = local disk under `public/uploads/<docId>/`. Notifications = in-app only
(email stubbed). Out of scope: OCR/full-text, e-signature, other depts' portals,
approver-on-leave routing.
