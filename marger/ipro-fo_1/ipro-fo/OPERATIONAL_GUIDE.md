# OPERATIONAL GUIDE — Java Core System v2.0

> Panduan operasional per modul. Setiap modul siap dioperasikan setelah `php artisan migrate --seed`.

---

## 0. Setup awal

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app npm install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app npm run build
```

→ http://localhost:8000

Login default:

| Email | Password | Role | Lihat menu |
|---|---|---|---|
| `admin@mup.local`         | `Admin@123` | SuperAdmin     | Semua |
| `rina.sales@mup.local`    | `Admin@123` | Sales          | Customer, Booking, Follow-up |
| `joko.smanager@mup.local` | `Admin@123` | SalesManager   | + Approval Center, Promote |
| `ani.finance@mup.local`   | `Admin@123` | Finance        | TTS, Kuitansi, Memo |
| `budi.fmanager@mup.local` | `Admin@123` | FinanceManager | + Post TTS, Void, Approve refund |
| `citra.collec@mup.local`  | `Admin@123` | Collection     | Follow-up, Denda, PJT, SKL |
| `direktur@mup.local`      | `Admin@123` | Direktur       | Dashboard executive, Approval final |

---

## 1. Modul Customer (Master)

**Akses:** `/customers` · Permission: `master.customer.view`

### Operasi Harian
1. **Tambah customer baru** dari menu Customer → tombol `+ Customer Baru`.
2. Isi minimal: nama, KTP, HP.
3. Sistem akan otomatis generate `code` mengikuti numerator `CUST` (format `CUST/{YYYY}/{SEQ}`).
4. NIK & NPWP otomatis di-encrypt di DB. Hanya user dengan permission `master.customer.view.sensitive` yang lihat full digits.
5. Audit log tercatat otomatis untuk setiap create/update/delete.

### Validasi
- NIK duplikat ditolak dengan error `DUPLICATE_ID_NUMBER`.
- Email format valid.
- Phone numeric only + simbol `+ - ()`.

### Penghapusan
Customer dengan booking aktif **tidak bisa dihapus**. Soft-delete saja (status `inactive`).

---

## 2. Modul Unit (Master Property)

**Akses:** `/units` · Permission: `master.unit.view`

### Operasi Harian
1. **List unit** dengan filter project, cluster, status, harga.
2. **Tambah unit baru** → `+ Unit Baru` → pilih project, cluster, tipe, harga.
3. **Site Plan interaktif** di `/projects/{id}/siteplan`:
   - SVG canvas dengan semua unit terplotting.
   - Hover → tooltip detail.
   - Klik filter status untuk highlight.
   - Mode `Edit` (permission `master.unit.siteplan.edit`) → drag-drop reposition unit.
   - **Realtime**: ketika unit di-booked di terminal lain, warna update otomatis via Reverb WebSocket (channel `private-project.{id}`).

### State Machine Unit
```
available → hold → booked → sold
                ↗  ↘
              cancelled
              maintenance
```

---

## 3. Modul Booking (Sales)

**Akses:** `/bookings` · Permission: `sales.booking.view`

### Wizard 5-Step (`/bookings/create`)
1. **Step 1 — Customer**: cari atau pilih customer existing.
2. **Step 2 — Unit**: grid unit AVAIL/HOLD dengan harga.
3. **Step 3 — Skema Pembayaran**: pilih dari 3 default (Cash Keras, Cicilan 24, KPR 70/30).
4. **Step 4 — Diskon & Gimmick**: input % diskon, pilih gimmick, lihat preview harga realtime.
5. **Step 5 — Konfirmasi**: review ringkasan + tanggal booking + catatan.

### Otomasi
- **Pricing**: PPN 11% otomatis dari config `domain.tax.ppn_default_pct`.
- **Numerator**: `BK/{PROJECT}/{YY}{MM}/{SEQ}` (5 digit).
- **Payment Schedules**: auto-generated dari `payment_scheme_terms`. Cash → 1 termin. KPR → 4 termin.
- **Unit Status**: AVAIL → BOOKED otomatis saat booking ACTIVE.
- **Hold Conversion**: jika ada hold aktif untuk customer & unit, otomatis dimark CONVERTED.

### Approval Diskon (Otomatis)
- **0-3%**: auto-confirmed (status `active`).
- **3-7%**: status `draft` + auto-create approval ke `SalesManager`.
- **7-12%**: 2-level approval (SalesManager → SalesDirector).
- **>12%**: 3-level (SalesManager → SalesDirector → Direktur).

### State Machine Booking
```
draft → nup → pilih_unit → ppjb → active → lunas → closed
       ↘  ↘   ↘            ↘
          cancelled (dari status mana pun kecuali closed/lunas)
```

### Aksi yang Tersedia
- **Promote ke PPJB** (`sales.booking.promote`): tombol muncul saat status `active`. Generate `Contract` record, status booking → `ppjb`.
- **Cancel Booking** (`sales.booking.cancel`): butuh alasan + nominal penalti opsional. Unit otomatis kembali AVAIL, payment schedule yang outstanding di-void, cancellation_requests record dibuat.

### Audit Trail
Semua transisi status tercatat di `booking_status_histories` (from, to, by, notes, timestamp).

---

## 4. Modul Kontrak / PPJB (Sales)

**Akses:** `/contracts` · Permission: `sales.contract.view`

### Operasi
1. Kontrak otomatis dibuat saat **Promote Booking ke PPJB**.
2. Status awal: `draft`.
3. Tombol `Tanda Tangan & Aktifkan` (permission `sales.contract.sign`):
   - Status kontrak → `signed`, signed_at = today.
   - Booking otomatis transisi `ppjb` → `active`.
4. Snapshot data customer/unit/harga disimpan di kolom `snapshot` JSON.

---

## 5. Modul TTS — Tanda Terima Setoran (Finance)

**Akses:** `/tts` · Permission: `finance.tts.view`

### Buat TTS Baru
1. `/tts/create` → cari customer.
2. Booking dropdown otomatis populated dari booking customer.
3. Pilih cara bayar: **cash / transfer / giro / VA**.
4. Untuk **Giro**, isi nomor giro, bank, jatuh tempo.

### Auto-Allocate (FIFO)
- Untuk cash/transfer/VA dengan booking_id valid → otomatis allocate ke schedule terlama yang outstanding.
- Setiap allocation tercatat di `tts_allocations`.
- Schedule status update: `outstanding` → `partial` / `paid` otomatis.

### Workflow Status
```
pending (giro) → verified → posted
              ↘            ↘
                cancelled    (Kuitansi auto-issued)
```

### Aksi Khusus Giro
- **Konfirmasi Cair** (`finance.tts.giro.manage`): saat clearing bank sukses → status `verified` + auto-allocate.
- **Tolak** (`finance.tts.giro.reject`): reverse semua alokasi + status `cancelled`. Schedule yang sudah paid kembali `outstanding`/`partial`.

### Posting (FinanceManager)
- Tombol `Posting TTS` muncul kalau status `verified`.
- Setelah posted → event `TtsPosted` di-fire → **AutoIssueKuitansi** listener menerbitkan kuitansi otomatis.

---

## 6. Modul Kuitansi (Finance)

**Akses:** `/kuitansi` · Permission: `finance.kuitansi.view`

### Auto-Generated
- Diterbitkan **otomatis** saat TTS di-posting (via event listener).
- Format nomor: `KW/{PROJECT}/{YY}{MM}/{SEQ}`.
- Field `terbilang` otomatis di-generate dari nominal pakai helper `terbilang()`.
- Item line: deskripsi referensi ke booking & TTS.

### Cetak
Halaman Show punya tombol cetak. Layout kuitansi formal siap-print.

---

## 7. Modul Memo (Finance)

**Akses:** `/memos` · Permission: `finance.memo.view`

### Operasi
- Memo Penerimaan (`incoming`) atau Pengeluaran (`outgoing`).
- Numerator `MEMO`: `MEMO/{YY}{MM}/{SEQ}`.
- Bisa dikaitkan ke entity lain via `reference_type`/`reference_id` (polymorphic).

---

## 8. Modul Collection

### 8.1 Follow-up (`/followups`)
- Sales catat aktivitas follow-up customer (call/visit/email/WA).
- Set `next_action_at` → reminder scheduled.
- Permission: `collection.followup.view` (read), `collection.followup.create` (write).

### 8.2 Denda (`/dendas`)
- Read-only. Dihitung otomatis tiap pagi via `billing:jatuh-tempo` (01:00).
- Rate default: 0.05%/hari (config `domain.denda.rate_per_day`).
- Permission: `collection.denda.view`.

### 8.3 PJT — Piutang Jatuh Tempo (`/pjt`)
- Auto-generated tiap 02:00 via `pjt:generate`.
- Aggregate per customer untuk schedule overdue.
- Tombol `Regenerate` manual untuk admin.
- Permission: `collection.pjt.view`, `collection.pjt.regenerate`.

### 8.4 Tunggakan (`/tunggakan`)
- Auto-updated tiap 02:30 via `tunggakan:update`.
- Kategori: **ringan** (≤2 termin), **sedang** (≤5), **berat** (>5).
- Trigger eskalasi: berat → siap untuk Surat Peringatan SP3.
- Permission: `collection.tunggakan.view`.

### 8.5 SKL — Surat Keterangan Lunas (`/skl`)
- Customer bisa minta SKL setelah 100% lunas.
- Sistem validasi: kalau masih ada outstanding, tolak dengan error `NOT_FULLY_PAID`.
- Numerator: `SKL/JRES/{YYYY}/{SEQ}`.
- Permission: `collection.skl.issue`.

---

## 9. Modul Approval Center

**Akses:** `/approvals` · Permission: `approval.view`

### Flow Generic
1. **Submit**: action lain (CreateBooking dengan diskon tinggi, CancelBooking, RequestRefund) memanggil `SubmitForApproval::execute(documentType, $approvable, $payload)`.
2. **Chain Resolver** cari chain yang match conditions (mis. discount_pct 3-7 → DISC-MID, 7-12 → DISC-HIGH).
3. **Steps** dibuat sesuai chain (level 1, 2, 3 dst).
4. **Notify**: approver level-1 dapat notifikasi (in-app + WA — TODO listener).
5. **Act**: approver klik Approve/Reject + catatan.
6. **Final approve** → event `ApprovalApproved` → trigger executor action (mis. confirm booking, release refund).

### Pre-seeded Chains
- `booking_discount` × 3 tier (mid/high/extreme)
- `booking_cancel` × 2 level (SalesManager → Direktur)
- `refund` × 1 level (FinanceManager)

### Centang Tanggung Jawab
- **Tab Tugas Saya**: approval yang Anda harus tindak.
- **Tab Semua**: untuk audit / monitoring (jika punya `approval.view`).

---

## 10. Modul Settings

**Akses:** `/settings` · Permission: `system.user.view`

### 10.1 Users
- CRUD user dengan role assignment multi-select.
- Reset password (kosongkan kalau tidak ganti).
- Block/Unblock individual.

### 10.2 Roles
- Edit deskripsi + permission matrix (grouped by module).

### 10.3 Numerators
- Lihat semua format penomoran.
- Preview nomor next (Cmd+K friendly).
- Edit prefix, format, reset strategy (yearly/monthly/daily/never).

### 10.4 Parameters
- Edit PPN %, denda %, hold default, NUP refund penalty, dll.
- Bulk save semua perubahan sekaligus.

---

## 11. Scheduled Tasks (Cron-style)

Berjalan otomatis lewat `scheduler` container atau `php artisan schedule:work`.

| Jam | Command | Fungsi |
|---|---|---|
| 00:30 | `hold:expire`        | Release unit_holds expired → unit kembali AVAIL |
| 00:40 | `booking:expire`     | Cancel draft booking > 14 hari |
| 01:00 | `billing:jatuh-tempo`| Tandai schedule overdue + hitung denda |
| 02:00 | `pjt:generate`       | Generate PJT header per customer |
| 02:30 | `tunggakan:update`   | Update kategori tunggakan |

Run manual:
```bash
php artisan hold:expire
php artisan billing:jatuh-tempo
# dst.
```

---

## 12. Realtime Events (Reverb)

### Channels
- `private-App.Models.User.{id}` — notifikasi personal user.
- `private-project.{id}` — siteplan updates per project.

### Events Broadcast
- `unit.status.changed` — saat unit AVAIL ↔ HOLD ↔ BOOKED. Payload `{unit_id, status}`.

Client-side (Vue):
```ts
window.Echo.private(`project.${projectId}`)
    .listen('.unit.status.changed', (e) => {
        // update local state, refresh siteplan color
    });
```

---

## 13. Search & Command Palette

Tekan **Cmd+K** (Mac) / **Ctrl+K** (Windows) di mana saja.

- Search customer by nama/code/phone.
- Search booking by nomor.
- Hasil mengarah ke detail page.

API: `GET /api/v1/search?q=...`

---

## 14. Audit Log

Setiap mutation di model yang pakai trait `HasAuditTrail` tercatat di tabel `audit_logs`:
- `event`: created / updated / deleted / restored / login / logout
- `auditable_type` + `auditable_id`: morph ke entity
- `old_values` / `new_values`: JSON diff
- `user_id` + `ip_address` + `user_agent` + `context`

Field sensitif (`password`, `id_number`, `npwp`, `mfa_secret`) otomatis di-strip.

---

## 15. Testing

```bash
# Semua test
docker compose exec app vendor/bin/pest

# Coverage
docker compose exec app vendor/bin/pest --coverage --min=70

# Specific test
docker compose exec app vendor/bin/pest tests/Feature/BookingFlowTest.php
```

Sudah include:
- ✅ NumeratorService — sequence increment, code not found, period reset
- ✅ Booking State Machine — valid/invalid transitions
- ✅ Money Value Object — arithmetic, percent
- ✅ Pricing Calculator — diskon, gimmick, PPN edge cases
- ✅ Booking Flow Test — auto-confirm vs draft (with approval)
- ✅ TTS Allocation Test — FIFO auto-allocate
- ✅ Customer API Test — CRUD + duplicate detection + permissions
- ✅ Auth API Test — login success/fail/blocked

---

## 16. Permission Slugs Summary

Total ~120 permission. Highlights:

| Module | Examples |
|---|---|
| system    | `system.user.view`, `system.role.update`, `system.setup.numerator` |
| master    | `master.customer.create`, `master.unit.update.price`, `master.unit.siteplan.edit` |
| sales     | `sales.booking.create`, `sales.booking.promote`, `sales.discount.approve` |
| finance   | `finance.tts.create`, `finance.tts.post`, `finance.kuitansi.issue` |
| collection| `collection.followup.create`, `collection.denda.waive`, `collection.skl.issue` |
| approval  | `approval.view`, `approval.act` |

Setiap permission default sudah di-seed dan di-assign ke role yang sesuai (lihat `RoleSeeder.php`).

---

## 17. Troubleshooting

| Issue | Solusi |
|---|---|
| Login `Invalid credentials` | Pastikan migrate-seed sudah jalan. Cek tabel `users`. |
| Permission denied 403 | User belum punya role yang tepat. Login sebagai admin → assign role. |
| Tagihan jatuh tempo tidak update | Scheduler tidak jalan. Check `docker compose ps` → container `scheduler` UP? |
| Approval tidak muncul | Chain belum di-seed. Run `php artisan db:seed --class=ApprovalChainSeeder`. |
| Site plan blank | Belum ada `siteplan_points`. Unit harus di-position lewat Edit Mode. |
| Kuitansi tidak auto-issue | Event listener belum ke-load. Run `php artisan event:clear && php artisan event:cache`. |

---

## 18. Production Hardening Checklist

Sebelum go-live (selain checklist di [REBUILD_BLUEPRINT_LARAVEL13.md](../REBUILD_BLUEPRINT_LARAVEL13.md#13-production-checklist)):

- [ ] Reset semua password seeded.
- [ ] `APP_DEBUG=false`, `APP_ENV=production`.
- [ ] Generate `APP_KEY` baru.
- [ ] Aktifkan MFA untuk SuperAdmin, Direktur, FinanceManager (config: `AUTH_MFA_REQUIRED_ROLES`).
- [ ] Setup HTTPS + HSTS di reverse proxy.
- [ ] Konfigur webhook secret di .env untuk Bank VA + PrivyID.
- [ ] Setup backup database harian ke S3/MinIO.
- [ ] Enable Sentry (DSN di env).
- [ ] Lock `/horizon`, `/telescope` di-disable.
- [ ] Cache: `php artisan config:cache route:cache view:cache event:cache`.
- [ ] Octane: `php artisan octane:start --workers=auto`.

---

**Akhir dokumen.** Untuk arsitektur dan blueprint lengkap, lihat [REBUILD_BLUEPRINT_LARAVEL13.md](../REBUILD_BLUEPRINT_LARAVEL13.md).
