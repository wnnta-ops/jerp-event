# 🚀 CUTOVER PLAN — Java Core System v2.0

**Project:** Java Core System (Property Developer ERP)
**Owner:** PT. Mitra Usaha Propertindo (MUP)
**Migrasi dari:** Java Core System Lama (eProperty/Batavianet, ASP.NET Web Forms)
**Status:** 7/7 Phase implementasi selesai — siap UAT & cutover

---

## 📋 Executive Summary

Java Core System v2.0 (Laravel 12 + Inertia + Vue 3) sudah mencapai **full feature parity** dengan sistem lama plus modernisasi UX/security. Dokumen ini merangkum rencana cutover dari sistem lama ke sistem baru.

### Status Implementasi (7 Phases)

| Phase | Modul | Status |
|-------|-------|--------|
| 1 | Foundation (12 module skeleton + auth) | ✅ |
| 2 | Customer Expansion (65 field, 8 sections) | ✅ |
| 3 | Payment Foundation (Free DP, Subsidi, Memo, Allocation) | ✅ |
| 4 | Contract Lifecycle (PPJB 4-state, Documents, Compliance) | ✅ |
| 5 | Gimmick Module (Package + ContractGimmick + tracking) | ✅ |
| 6 | Calculators (BPHTB/KPR/Skema) + 7 UX wins | ✅ |
| 7 | Migration Tools (Activity Log, Error pages, Import, Numerator) | ✅ |

---

## ✅ Pre-Cutover Checklist

### Infrastruktur
- [ ] Server production siap (PHP 8.4+, MySQL/MariaDB, Nginx/Apache)
- [ ] SSL certificate terpasang (HTTPS wajib)
- [ ] `.env` production di-set: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`
- [ ] Database backup otomatis terjadwal
- [ ] Redis untuk cache/queue/session (opsional, recommended)
- [ ] Queue worker + scheduler (cron) berjalan
- [ ] Storage permission + symlink (`php artisan storage:link`)

### Aplikasi
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `npm run build` (production assets)
- [ ] `php artisan migrate --force`
- [ ] `php artisan db:seed --class=ProductionSeeder --force` (roles, permissions, numerators, parameters, data sources, gimmick packages)
- [ ] `php artisan config:cache && route:cache && view:cache`
- [ ] `php artisan optimize`

### Smoke Test (WAJIB sebelum go-live — deploy gate)
Jalankan ketiga ini, semua harus PASS 100%:
- [ ] `php artisan trial:all`   — 24 domain action (create/update per modul)
- [ ] `php artisan smoke:pages` — 41 halaman web (deteksi 5xx, missing relation/class)
- [ ] `vendor/bin/phpunit`      — unit + feature test

> `smoke:pages` adalah jaring pengaman yang me-render SEMUA halaman index/show/export
> sebagai admin via HTTP kernel penuh. Menangkap RelationNotFoundException, class-not-found,
> dan render error yang TIDAK tertangkap `trial:all` (yang hanya menguji domain action).

### Security
- [ ] Semua default password di-reset
- [ ] MFA aktif untuk role Direktur & FinanceManager (future enhancement)
- [ ] Rate limiting aktif (login throttle)
- [ ] Audit log aktif & bekerja
- [ ] Review permission matrix per role

---

## 🗄️ Data Migration Plan

### Urutan Migrasi (dependency order)

```
1. Master Data
   ├── Projects + Clusters + Blocks
   ├── Unit Types + Units (+ pricelist)
   ├── Data Sources (marketing channels)
   ├── Payment Schemes
   └── Gimmick Items + Packages
2. Customers          → import:customers
3. Bookings           → (manual/script — link customer+unit)
4. Contracts          → (link booking)
5. Payment Schedules  → (link booking)
6. Payment History    → (link schedule, create allocations)
```

### Migration Tools Tersedia

| Tool | Command | Status |
|------|---------|--------|
| Customer import | `php artisan import:customers <file.csv> [--dry-run]` | ✅ Ready |
| Booking import | _(roadmap — extend pattern)_ | ⏳ |
| Contract import | _(roadmap)_ | ⏳ |
| Schedule import | _(roadmap)_ | ⏳ |
| Payment import | _(roadmap)_ | ⏳ |

### Numerator Format Compatibility

Sistem baru support **Roman numeral month** untuk parity nomor lama:
- Java Core System Lama: `0000670/RSV/JR/V/26`
- Format token: `{SEQ}/{PREFIX}/JR/{MM_ROMAN}/{YY}` → `0000670/RSV/JR/V/26` ✅

### Status Code Mapping (Java Core System Lama → Baru)

| Lama | Baru (BookingStatus) |
|------|----------------------|
| A (Active) | Active / Ppjb / Lunas |
| E (Expired/Erased) | Cancelled / Closed |

⚠️ **Perlu konfirmasi tim MUP** untuk kode status lain (P, C, S, dll).

### Migration Validation Protocol

1. **Export** sample 100 customer + 50 kontrak dari sistem lama
2. **Dry-run** import: `php artisan import:customers sample.csv --dry-run`
3. **Reconcile** — bandingkan jumlah record + spot-check 10 record
4. **Full import** setelah validasi
5. **Post-migration audit** — verify totals (customer count, booking value, outstanding)

---

## 🧪 UAT Scenarios

### Scenario 1: Customer Lifecycle
- [ ] Buat customer baru (8 sections, "Sama dengan KTP" toggle)
- [ ] Cari customer existing (recent panel anti-duplikasi)
- [ ] Edit customer data
- [ ] Verify audit log mencatat perubahan

### Scenario 2: Booking → Contract → PPJB
- [ ] Buat booking dari Site Plan (klik unit available → "Buat Booking")
- [ ] Booking dengan diskon → approval flow
- [ ] Promote booking ke kontrak (PPJB)
- [ ] PPJB lifecycle: Print → (lengkapi dokumen KK/KTP/NPWP) → Tanda Tangan → Selesai
- [ ] Verify document compliance gate (tidak bisa sign tanpa dokumen lengkap)

### Scenario 3: Payment & Free DP
- [ ] TTS payment → auto-kuitansi
- [ ] Grant Free DP → verify schedule jadi paid via Diskon
- [ ] Grant Subsidi DP (partial) → verify FIFO allocation
- [ ] Cancel Free DP → verify schedule kembali unpaid
- [ ] Verify Contract summary (Total Tagihan, Pembayaran, Pelunasan%)

### Scenario 4: Gimmick
- [ ] Apply paket gimmick CLUSTER 3.4 ke kontrak (7 items)
- [ ] Toggle "Diterima" per gimmick
- [ ] Verify total marketing cost

### Scenario 5: Calculators
- [ ] BPHTB calc (verify vs perhitungan manual)
- [ ] KPR calc (verify cicilan vs Excel)
- [ ] Skema calc (verify DPP/PPN/Nilai Kontrak)

### Scenario 6: CRM Activity Log (Jurnal)
- [ ] Catat aktivitas (Follow Up, Telepon, Meeting) di tab Jurnal kontrak
- [ ] Verify entry tersimpan & ter-list

### Scenario 7: Error Handling
- [ ] Session expired → halaman "Sesi Berakhir" + Login Ulang
- [ ] Access denied → halaman "Akses Ditolak"
- [ ] 404 → halaman "Halaman Tidak Ditemukan"

---

## 🔄 Cutover Day Runbook

### T-7 hari
- Final UAT sign-off dari tim MUP
- Freeze development sistem lama (no new data)
- Full export data sistem lama

### T-1 hari
- Final data migration ke staging
- Reconciliation report
- Smoke test di staging

### T-0 (Cutover)
1. **Maintenance mode** sistem lama (read-only)
2. **Final delta migration** (data sejak last export)
3. **Switch DNS / deploy** sistem baru ke production
4. **Smoke test** production:
   - Login semua role
   - Buka 1 customer, 1 booking, 1 kontrak
   - Cek dashboard KPI
5. **Go / No-Go decision**
6. Jika Go → announce ke user, sistem lama jadi archive
7. Jika No-Go → rollback (lihat di bawah)

### T+1 hari
- Monitor error log + performance
- Standby support untuk user questions
- Collect feedback

---

## ⏪ Rollback Plan

Jika cutover gagal:

1. **DNS revert** ke sistem lama (TTL rendah disiapkan sebelumnya)
2. Sistem lama dari maintenance → active mode
3. Data yang masuk ke sistem baru selama window di-export & merge manual ke lama
4. Root cause analysis sebelum retry

**Rollback window:** maksimal 4 jam setelah cutover.

---

## 📊 Success Metrics (Post-Cutover)

| Metric | Target |
|--------|--------|
| Login success rate | > 99% |
| Page load time (p95) | < 2 detik |
| Error rate | < 0.5% |
| Data migration accuracy | 100% (reconciled) |
| User-reported critical bugs (week 1) | 0 |
| Customer/booking/contract count match | 100% |

---

## 🎯 Post-Cutover Enhancements (Backlog)

Fitur yang bisa ditambahkan setelah go-live stabil:

1. **MFA / 2FA** untuk role finansial
2. **Booking/Contract/Schedule/Payment bulk import** UIs (saat ini customer only)
3. **Report Center** dengan custom report builder
4. **Jadwal Komisi** detail (commission schedule per kontrak)
5. **Proses KPR tab** full integration (KPA detail di contract view)
6. **Waiting List** per unit (booking queue)
7. **Attachment upload** untuk Activity Log & Contract Documents
8. **Mobile app** via REST API (Sanctum sudah siap)
9. **WhatsApp/Email notification** dispatcher
10. **Dashboard per-role** (Sales/Finance/Direktur views)

---

## 📚 Reference Documents

- `GAP_ANALYSIS_OLD_vs_NEW.md` — full observation Java Core System Lama vs baru
- `IMPLEMENTATION_ROADMAP.md` — 7-phase roadmap detail
- `OPERATIONAL_GUIDE.md` — operational procedures
- `GETTING_STARTED.md` — setup & onboarding
- `MODULES_COMPLETE.md` — module coverage

---

## 👥 Sign-Off

| Role | Nama | Tanggal | Tanda Tangan |
|------|------|---------|--------------|
| IT Lead (MUP) | _______ | _______ | _______ |
| Sales Manager | _______ | _______ | _______ |
| Finance Manager | _______ | _______ | _______ |
| Direktur | _______ | _______ | _______ |

---

🤖 Cutover plan ini living document — update sesuai progress UAT & keputusan stakeholder.
