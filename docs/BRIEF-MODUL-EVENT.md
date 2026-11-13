# Brief for Claude Code — Modul Event (Pilih Unit customer tracker)

Full PRD: https://claude.ai/artifact/4UkF2Nhq6CoUELkqRoV18d — read it for the why. This brief is the what and how for **Phase 1 (MVP)**.

## 0. Before you write code

1. Read `CLAUDE.md` and `CLAUDE.local.md`. Follow their conventions (new migrations only, services hold logic, Indonesian UI text, Pest tests).
2. Create a branch from the current main line: `feat/modul-event`.
3. Read these FO files. **Do not modify any of them**:
   - `app/Modules/FO/Launching/Models/NupRegistration.php` (`fo_nup_registrations`)
   - `app/Modules/FO/Launching/Models/NupEvent.php` (`fo_nup_events`)
   - `app/Modules/FO/Launching/Services/AktivasiNupService.php`
   - `app/Modules/FO/Launching/Services/PilihUnitService.php` (`lookupByBarcode`)
   - `app/Modules/FO/MarketingSales/Services/CustomerQrService.php`
   - `app/Filament/Fo/Pages/AktivasiNup.php`
4. Also read `config/akses.php`, `app/Filament/Concerns/MematuhiIzin.php` and `app/Console/Commands/Akses/*` (access rules), then summarise your plan before building.

**Decided (don't ask again):**
- Superadmin = the **existing** super roles, with the **same rules** as the rest of J-ERP: `config('akses.peran_super')` (`super_admin`, `sup`) always passes. Do not create a new superadmin role.
- One customer **can** have several APPROVED NUPs in the same event, and **each NUP queues separately** through the pos. Track per NUP, not per customer.

## 1. What we're building

A new **Event** module in J-ERP. Customers go through a fixed sequence of pos (checkpoints) at an event. Staff scan the customer's FO QR at each pos, and the system records where every customer is, how long they spend at each pos, and how long they wait between pos.

- **Pos 1 = FO Aktivasi NUP (already exists).** Reception scans the QR there and FO sets `status='CHECK_IN'` and `tgl_check_in=now()`. We **read** that as check-in. We add nothing at reception.
- **Every pos after pos 1 has two scan points**, each on a separate phone: **Masuk** (start of service) and **Keluar** (end of service). Both scan the same customer QR.
- Service time = Keluar − Masuk. Waiting time = Masuk at this pos − Keluar at the previous pos (for pos 2: − FO `tgl_check_in`).
- The module is generic: every event is configured by data (pos, scan points, access). Pilih Unit is just the first event.

## 2. Hard rule: FO is read-only

- No edits to any file under `app/Modules/FO`, `app/Filament/Fo`, or any `fo_*` migration/table.
- All FO access goes through **one** class: `app/Modules/Event/Services/FoNupLookup.php`. Nothing else in the module imports FO models.
- `FoNupLookup` only runs SELECT queries. It must never call `AktivasiNupService`, `PilihUnitService`, `CustomerQrService::ensureCode()` (that one writes a code!), or any FO method that writes, sends WA/email, or changes status.
- FO models use `TenantAwareModel` (global `TenantScope`). In queued jobs / scheduler, set the tenant context the same way existing jobs do, or the queries return nothing.

### QR / lookup rules (mirror `PilihUnitService::lookupByBarcode`, read-only)

- QR payload is `CUST:<customer.code>` (prefix `CustomerQrService::PREFIX`). With the prefix, look up **only** by customer code, never by No. NUP (migrated codes are numbers that collide with No. NUP).
- Without the prefix (manual entry), try `no_nup` first, then customer code.
- Always filter by the event's `fo_nup_event_id`.
- **One customer can have several APPROVED NUPs in the event.** A `CUST:` scan therefore returns a **list** of candidate NUPs, not one. A No. NUP entered manually returns exactly that NUP.
- Accept only: `verif_status = 'APPROVED'`, `tgl_check_in` not null (status `CHECK_IN` or `PILIH_UNIT`), `is_hold = false`.

`FoNupLookup` methods:
- `findByScan(string $payload, int $nupEventId): Collection<FoParticipantData>` (0..n NUPs; DTO: nup_registration_id, no_nup, customer_code, name = customer `full_name` ?: `nama_pendaftar`, phone, verif_status, status, is_hold, tgl_check_in)
- `checkedInSince(int $nupEventId, ?CarbonInterface $since): Collection<FoParticipantData>`
- `cancelledSince(int $nupEventId, Collection $knownIds)`: ids whose `tgl_check_in` went back to null (FO `cancelAktivasi`)

## 3. Structure

```
app/Modules/Event/
  Models/        EtEvent, EtStation, EtScanPoint, EtAccess, EtParticipant, EtVisit, EtScanLog
  Services/      FoNupLookup, EventSetupService, ScanService, SyncCheckInService, EventReportService
  Jobs/          SyncCheckInJob
  Exports/       EventExport (multi-sheet, use the same Excel lib as FO Exports)
  Policies/      EventPolicy (access rules below)
app/Providers/Filament/EventPanelProvider.php   → path /event, title "Event"
app/Filament/Event/Resources/EventResource.php  → setup wizard + list
app/Filament/Event/Pages/LiveBoard.php
app/Filament/Event/Pages/Laporan.php
app/Livewire/Event/Scanner.php (+ blade)        → mobile scanner, route /event/scan, auth required
```

Models extend `TenantAwareModel`. Tables use the `et_` prefix. Register the panel on the portal dashboard the same way the other panels are listed.

## 4. Tables (new migrations)

| Table | Columns |
| --- | --- |
| `et_events` | id, tenant_id, name, event_date, venue, fo_nup_event_id (FK-less int, index), status enum draft/open/closed, opened_at, closed_at, last_synced_at, created_by, timestamps, soft deletes |
| `et_stations` | id, tenant_id, event_id, code, name, sequence, type enum fo_checkin/normal/finish, target_minutes nullable, timestamps |
| `et_scan_points` | id, tenant_id, station_id, kind enum masuk/keluar/single, label, timestamps |
| `et_access` | id, tenant_id, event_id, user_id, scan_point_id nullable, can_live_board bool, can_reports bool, timestamps. Unique(event_id, user_id, scan_point_id) |
| `et_participants` | id, tenant_id, event_id, fo_nup_registration_id, no_nup, customer_code, name_snapshot, phone_snapshot, checked_in_at, finished_at, status enum active/finished/left/cancelled, current_station_id, current_state enum waiting/in_service/done, timestamps. Unique(event_id, fo_nup_registration_id) |
| `et_visits` | id, tenant_id, participant_id, station_id, started_at nullable, ended_at nullable, started_by, ended_by, end_reason enum scan_keluar/manual/next_scan/koreksi/event_closed, flags json, timestamps |
| `et_scan_logs` | id, tenant_id, event_id, scan_point_id, user_id, raw_payload, fo_nup_registration_id nullable, result enum accepted/rejected/duplicate/undone, reason, server_time, user_agent, created_at. Append-only |

No FK constraints to `fo_*` tables (keeps FO untouched); plain indexed columns.

## 5. Roles & access

Use J-ERP's existing access system. Don't build a separate one.

- Add the panel to `config/akses.php` (`'panel' => ['event' => 'panel.event']` and `'modul' => ['event' => 'panel.event']`), map the Event resources/pages under `'resource'`, and use `MematuhiIzin` on them. Then run `php artisan akses:lengkapi` so super roles get the new permissions, and check `php artisan akses:audit` shows nothing unguarded.
- **Superadmin** = any role in `config('akses.peran_super')`, with the same rule as everywhere else: always passes. Sees every event; can create/edit/open/close events, manage access, scan at any scan point, use the live board, correct visits, and export.
- Respect the `akses.tegakkan` switch the same way existing panels do (report mode vs enforce mode).
- **Scanner** accounts: see only events where they have `et_access` rows, and only the scan points listed there. The scanner opens straight on the scan point if they have just one.
- `can_live_board` / `can_reports` on `et_access` give koor lap / viewer rights per event.
- Access is checked **server-side on every scan** in `ScanService`, not just in the UI. Closing an event ends scanner access.

## 6. Setup wizard (Filament, superadmin only)

1. Detail: name, date, venue, pick FO NUP event (select from `fo_nup_events` via `FoNupLookup`, show code + name + tgl_launching).
2. Pos: repeater with name, code, sequence, target_minutes. First row is fixed `fo_checkin` ("Aktivasi NUP (FO)"); last row is marked `finish`.
3. Scan point: auto-created Masuk + Keluar for every pos after the first; can rename or switch a pos to `single`.
4. Akses: per user, tick scan points + live board + reports.
5. Review → Buka event.
Also: "Duplikat event" (clone pos, scan points, access) and "Tutup event".

## 7. Scanner (Livewire, mobile-first)

- Pick event → scan point once (remembered in localStorage); big header "Pilih Unit · MASUK".
- Camera scanning in the browser (use `html5-qrcode` or similar via npm/Vite), plus a manual input box.
- POST to `ScanService::handle($scanPointId, $payload, $user)` → JSON result. Show a full-screen green/red card: name, No. NUP, message, plus vibration/sound. Back to camera in ~1.5 s.
- Masuk phone list: "Menunggu untuk pos ini". Keluar phone list: "Sedang dilayani" with live timers and a **Selesai** button per row.
- "Batalkan scan terakhir" button available for 30 s.

### One customer, several NUPs

A participant = **one NUP** (`et_participants` unique on event + `fo_nup_registration_id`). Each NUP has its own journey, visits and timers. When a `CUST:` scan matches more than one NUP:

- **Keluar**: if exactly one of the NUPs has an open visit at this pos, use it automatically.
- **Masuk**: drop NUPs that are finished, cancelled, or already in service. If exactly one is left, use it automatically.
- Otherwise, show a picker on the scanner ("Customer ini punya 2 NUP — pilih: NUP 0123 / NUP 0124") with big buttons; the choice is sent back with the `nup_registration_id`. Typing the No. NUP skips the picker.
- Live board and reports show the No. NUP on every card, and the per-customer view groups a customer's NUPs together.

### ScanService rules (one DB transaction, lock the participant row)

- Validate access, event `open`, lookup (local participants first; fall back to `FoNupLookup::findByScan` and create missing participants), then resolve to one NUP (above).
- Duplicate: same scan point + same participant within 5 s → `duplicate`, no change.
- **Masuk**: if **the same NUP** has a visit open at a different pos, close it at now with `next_scan` + flag `estimated`. Then open a visit here. Warn (don't block) if out of order.
- **Keluar**: close the open visit at this pos. If none is open, create one with started_at = previous pos's ended_at (or checked_in_at) and flag `tanpa_masuk`. If this is the `finish` pos → participant `finished`.
- Already `finished` → reject "Sudah selesai".
- Every attempt writes `et_scan_logs`.

Rejection messages (Indonesian): "QR tidak dikenali", "Kode tidak ditemukan", "Bukan peserta event ini", "NUP belum disetujui", "Belum aktivasi di pos 1", "NUP sedang di-hold", "Sudah selesai", "Akun tidak punya akses ke pos ini", "Event belum dibuka".

## 8. Check-in sync

`SyncCheckInJob` runs every minute from the scheduler and loops every ~10 s for open events (or use a short-interval schedule). Per open event: `checkedInSince(last_synced_at)` → upsert participants with `checked_in_at = tgl_check_in`, state `waiting` for pos 2; `cancelledSince` → status `cancelled`. Superadmin also has a "Sinkron sekarang" button.

## 9. Live board & reports

- Live board (poll every 5 s): one column per pos with counts of waiting / being served and the longest current time; cards turn amber/red past `target_minutes`; search by name / No. NUP / customer code; correction actions with a required reason (force close, start, mark `left`, reopen).
- Reports, per event only: per-pos count, avg / median / P90 service time, avg / P90 waiting; per-customer journey; throughput per hour.
- **Export per event** to Excel with sheets `Ringkasan`, `Per Pos`, `Per Customer`, `Kunjungan`, `Log Scan`; filters for time range, pos, status. Superadmin + `can_reports` only.

## 10. Tests (Pest, `tests/Feature/Event/`)

- FoNupLookup: `CUST:` prefix never matches no_nup; filters by nup_event_id; rejects non-APPROVED / not checked in / hold; returns all NUPs of a customer in the event.
- Multi-NUP: auto-pick on Keluar / Masuk when only one candidate; picker when two; each NUP keeps its own visits and timers.
- Access: super roles from `akses.peran_super` pass everything; a scanner account is rejected on an unassigned scan point.
- ScanService: Masuk→Keluar timing, waiting time, auto-close on next Masuk, Keluar without Masuk, duplicate window, finish, access denied, event closed.
- Sync: creates participants from `tgl_check_in`; cancelled activation.
- A test that asserts no `fo_*` row changes after a full scan flow (snapshot row counts + updated_at).

## Out of scope (Phase 2)

Offline scan queue, desk/table numbers, TV mode, websockets (Reverb), comparing events.

## Done when

Superadmin can set up and open an event linked to an FO NUP event; reception activates in FO as usual; scanner accounts scan Masuk/Keluar on their phones; the live board shows everyone's location and timers; the export per event matches a manual stopwatch check in a dry run; and `git diff` shows no changes under FO.
