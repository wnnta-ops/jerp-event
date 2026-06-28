# Riwayat Pengembangan — Java Core System

> **Dokumen backup ringkasan seluruh percakapan & pekerjaan pengembangan.**
> PT. Mitra Usaha Propertindo (MUP) · Java Residence
> Disusun: 4 Juni 2026 · Status: aktif dikembangkan

---

## Daftar Isi

1. [Ikhtisar Proyek](#1-ikhtisar-proyek)
2. [Tech Stack & Cara Akses](#2-tech-stack--cara-akses)
3. [Konvensi & Pola Teknis Kunci](#3-konvensi--pola-teknis-kunci)
4. [Kronologi Pengembangan](#4-kronologi-pengembangan)
   - [Fase A — Pembangunan Awal & Rebuild eProperty](#fase-a--pembangunan-awal--rebuild-eproperty)
   - [Fase B — Paritas Modul SALES eProperty (Phase 2–7)](#fase-b--paritas-modul-sales-eproperty-phase-27)
   - [Fase C — Business Support / Akuntansi (Batavianet BO)](#fase-c--business-support--akuntansi-batavianet-bo)
   - [Fase D — Pajak, CRM, Security, Setup Sales](#fase-d--pajak-crm-security-setup-sales)
   - [Fase E — 4 Batch Paritas BO + SPK Mutasi Progress](#fase-e--4-batch-paritas-bo--spk-mutasi-progress)
   - [Fase F — Pengujian Siklus End-to-End (8 siklus)](#fase-f--pengujian-siklus-end-to-end-8-siklus)
   - [Fase G — Deployment 24/7](#fase-g--deployment-247)
   - [Fase H — Dokumentasi Lengkap (PRD dll)](#fase-h--dokumentasi-lengkap-prd-dll)
   - [Fase I — CIF 360° + Cetak/Excel](#fase-i--cif-360--cetakexcel)
   - [Fase J — Modul Booked Revenue (baru)](#fase-j--modul-booked-revenue-baru)
   - [Fase K — Proyek Terkait: Dashboard Exsum (Firebase)](#fase-k--proyek-terkait-dashboard-exsum-firebase)
5. [Daftar Bug yang Ditemukan & Diperbaiki](#5-daftar-bug-yang-ditemukan--diperbaiki)
6. [Aset Dokumentasi yang Dihasilkan](#6-aset-dokumentasi-yang-dihasilkan)
7. [Status Verifikasi Terkini](#7-status-verifikasi-terkini)
8. [Lampiran — File & Migrasi Kunci](#8-lampiran--file--migrasi-kunci)

---

## 1. Ikhtisar Proyek

**Java Core System** adalah ERP pengembang properti (property developer) untuk PT. Mitra Usaha Propertindo, dibangun ulang (rebuild) agar **setia meniru (faithful mirror)** sistem live **eProperty**:

- **Front Office (FO)** — `fo.javaresidence.com` (Sales, Booking, Kontrak, Collection, dll.)
- **Back Office (BO) "Batavianet"** — `bo.javaresidence.com` (Akuntansi/Business Support)

Tujuan: satu sistem terpadu yang mereplikasi alur kerja eProperty + BO, dengan akuntansi double-entry penuh (General Ledger), agar bisa dipakai semua user kapan pun & di mana pun.

---

## 2. Tech Stack & Cara Akses

| Komponen | Teknologi |
|----------|-----------|
| Backend | **Laravel 12** + PHP 8.4 (native, tanpa Docker) |
| Frontend | **Inertia.js + Vue 3 + TypeScript + Tailwind CSS** (dark-mode) |
| Database | **SQLite** (WAL-tunable via env) |
| Arsitektur | **DDD-lite** — `app/Domain/<Context>/{Models,Services,Actions}` |
| Otorisasi | **Spatie Permission** (RBAC) — 193 permission / 11 role; `Gate::before` → SuperAdmin akses semua |
| Akses jarak jauh | **Tailscale funnel** → `https://java-residence.tail212f65.ts.net` |

**Verification trinity** (dipakai di tiap perubahan):
- `npm run build` — kompilasi vue-tsc + Vite
- `php artisan smoke:pages` — render-test **171 halaman** Inertia
- `php artisan trial:all` — **24** trial transaksi end-to-end

---

## 3. Konvensi & Pola Teknis Kunci

- **`JournalService::post(header, lines)`** — satu-satunya gerbang posting GL. Memvalidasi **Dr = Cr**, minimal 2 baris, periode tidak closed; menyalurkan dimensi per-baris (cost_center, department, ledger, location, currency, kurs).
- **Trait** `HasTenant` (auto `tenant_id` + scope), `HasAuditTrail`; cast `EncryptedString`.
- **Strict-mode Eloquent** — akses atribut/relasi yang belum di-load akan error → pakai eager-loading atau `DB::table()`.
- **Resolusi akun** via `config('business_support.gl_accounts.*')` (mis. `receivable` = 10102010101 Piutang Usaha; `income` = 40101010101 Penjualan Usaha; `unearned_revenue`/Uang Muka Penjualan = 20106060601).
- **Penomoran** transaksi via pola `nextNumber()` per modul.
- **Komponen UI** reusable: `SearchSelect.vue` (combobox cari kode/nama), `Button`, `Badge`, `EmptyState`, dll.
- **CoA riil eProperty** 6-level di `database/data/eproperty_coa.php` (di-seed `EpropertyCoaSeeder`).

---

## 4. Kronologi Pengembangan

### Fase A — Pembangunan Awal & Rebuild eProperty

- Menyusun **blueprint rebuild** (Laravel) + dokumen pendamping (skema database, referensi API & frontend).
- Scaffold proyek + foundation (Trait, Enum, Helper, Middleware, Provider) + seeders (Permission, Role, User, Numerator, Parameter).
- Instalasi tanpa Docker: PHP 8.4 + Composer + NPM + build + migrate + seed.
- Modul inti: **Customer (CIF), Unit (+Siteplan), Booking (wizard + state machine), Contract (PPJB), TTS, Kuitansi, Memo**, Approval Engine generik, **Collection** (Followup/Denda/PJT/Tunggakan/SP/SKL), **Settings** (User/Role/Numerator/Parameter), Scheduler, NUP, Hold.
- **Bank Loan (KPA)** end-to-end, **Legal** (PPJB/AJB/BAST/IMB/Sertifikat), **Commission engine + payout**, **Faktur Pajak + Cashback + Refund**, **Virtual Account + Webhook**, **Exception flow** (GN/GU/Reschedule + SP), PDF (Browsershot) + Notification Dispatcher, API lengkap + bulk export/import.
- Pembuatan `trial:all` (24 trial) + `smoke:pages` (safety net) + LIVE_DEMO_LOG.
- **Rebrand** IPRO FO → **Java Core System**.

### Fase B — Paritas Modul SALES eProperty (Phase 2–7)

Observasi read-only sistem live eProperty, lalu membangun fitur yang kurang:

- **Phase 2 — Customer**: +30 field (agama, status nikah, kewarganegaraan, sumber data), AddressClone, form 7-section, panel Recent Customers.
- **Phase 3 — Memo & Free DP**: Memo + Free DP/Subsidi DP (grant + cancel).
- **Phase 4 — PPJB lifecycle**: dual numbering, contract_documents + compliance, Tab Proses PPJB.
- **Phase 5 — Gimmick**: 8 tipe, paket gimmick, ContractGimmick, Tab Gimmick.
- **Phase 6 — Kalkulator**: BPHTB, KPR, Skema (payment_schemes), UX Quick Wins, Site Plan "Buat Booking".
- **Phase 7 — Pelengkap**: CRM Activity Log (Jurnal polimorfik), error pages Indonesia, Numerator multi-format + cluster code, Bulk Import + migration validator, cutover/UAT plan.

### Fase C — Business Support / Akuntansi (Batavianet BO)

Katalog & deep-dive BO eProperty (CoA, Jurnal, AP/AR, Laporan), gap analysis, lalu bangun:

- **AP & AR** (register + pay + jurnal GL), aging + kartu hutang/piutang.
- **Cash Book** (BKM/BKK auto-journal), **Bon Sementara** (uang muka operasional), **Cek/Giro Masuk** (terima/kliring/tolak).
- **Fixed Asset + Penyusutan** + lifecycle approval jurnal (Baru→Final→Approved→Closed).
- **Budget Control** (budget vs realisasi), **Contractor (SPK)** (progress/opname + retensi + AP), **COGS/HPP** (+ RealCost alokasi pool CIP ke unit), **Laporan Arus Kas** (Direct + Indirect).
- **Employee Center + Payroll** (run + payslip + jurnal GL), Neraca Lajur + komparasi bulanan.
- Rebuild **AP faithful** (wizard multi-langkah, kategori invoice, Kuitansi Hutang, Posting Invoice PO/SPK, Memorial Hutang, Kelengkapan Berkas, Penghapusan Nilai Hutang).
- Rebuild **AR faithful** (kategori + lines + schedules, wizard 5-langkah, Kuitansi Piutang, Memorial Piutang).

### Fase D — Pajak, CRM, Security, Setup Sales

- **Faktur Pajak**: NSFP pada AP/AR, keluaran/masukan/rekap, export CSV + XML e-Faktur DJP, PPN Penjualan Properti, PPh Rekap (21/23/4(2)), Rekonsiliasi Bank, **Tutup Buku** (closing L/R → laba ditahan), Buku Pembantu Pajak + cetak SPT Masa, Neraca Awal otomatis (carry-forward), Jurnal Saldo Awal, **Dashboard Pajak**.
- **CRM**: crm_leads + LeadStage enum, Kanban + funnel + convert + follow-up.
- **Security** & **Setup Sales** modul (faithful dari eProperty).

### Fase E — 4 Batch Paritas BO + SPK Mutasi Progress

Empat batch penutup gap BO, semua dengan auto-journal balanced:

1. **Batch 1 — Aset**: Disposal + Penjualan aset + Opening Penyusutan.
2. **Batch 2 — Transaksi**: AP/AR/PO/SPK lanjutan.
3. **Batch 3 — Budget**: workflow (ajukan→setujui→mutasi) + Upload massal + Saldo Awal.
4. **Batch 4 — Laporan**: Laporan Proyeksi + COGS RealCost.
5. **SPK Mutasi Progress** (`MutasiProgressRegis1`) — reklasifikasi CIP antar cost-center.

### Fase F — Pengujian Siklus End-to-End (8 siklus)

Menguji **39 sub-transaksi** lewat controller nyata (pola: login user → buat Request → panggil controller → cek flash `error` + jurnal balanced, dibungkus `DB::beginTransaction()`/`rollBack()`):

| Siklus diuji | Hasil |
|--------------|-------|
| SPK + Fixed Asset | 0 bug |
| AR (tagih→reschedule→terima) / AP (→kuitansi→faktur pajak) | 0 bug |
| COGS RealCost, **Budget**, Kas Kecil/Cash Book | **1 bug ditemukan & diperbaiki** (Budget) |
| Cek/Giro, Bon Sementara, Payroll, Tutup Buku | 0 bug |

Total: **hanya 1 bug riil** di seluruh 39 sub-transaksi; semua jurnal balanced.

### Fase G — Deployment 24/7

Permintaan: web aktif selalu tanpa laptop menyala, bisa dipakai semua user. Direkomendasikan **Oracle Cloud Always Free** (VM 24/7 gratis). Disiapkan paket deployment:

- `.env.production.example`, `deploy/Caddyfile` (php_fastcgi unix socket), `deploy/javacore-worker.service` (systemd queue), `deploy/deploy.sh`.
- `DEPLOY.md` (runbook) + **`MULAI-DISINI.md`** (langkah satu per satu + link pendaftaran).

> Pengguna melakukan sendiri provisioning/login/pembayaran; asisten hanya menyiapkan paket & panduan.

### Fase H — Dokumentasi Lengkap (PRD dll)

Folder **`docs/`** berisi 4 dokumen × 4 format (`.md`, `.html`, `.doc`, `.pdf`):

- **PRD-Java-Core-System** — Product Requirements Document lengkap.
- **Spesifikasi-Modul-Detail** — penjelasan rinci **44 modul**.
- **Diagram-Alur-Modul** — **40+ flowchart** (Mermaid) per modul.
- **Ringkasan-Eksekutif** — executive brief.
- Tooling: `docs/_build.php` (CommonMark → HTML; Chrome headless → PDF), `docs/_schema.txt` (184 tabel), `docs/_diagrams.md`.

### Fase I — CIF 360° + Cetak/Excel

- **CustomerActivityService** — timeline 360° aktivitas customer dari **~25 modul** (CRM, NUP, Hold, Booking, PPJB, TTS, Kuitansi, Memo, Cashback, Refund, Komisi, KPA, Legal, Pajak, VA, Collection, Exception, Piutang BS, Activity Log). Pakai `DB::table()` + guard `Schema::hasTable` agar lintas-domain & aman strict-mode.
- Halaman **CIF Show** dapat kartu timeline + filter chip per modul.
- **Output report per customer**: tombol **Cetak** (HTML A4 auto-print) + **Download Excel** (`.xls` HTML-table, 5 tabel: Profil, Ringkasan, Kontrak, Jadwal, Riwayat Aktivitas lengkap).

### Fase J — Modul Booked Revenue (baru)

Modul akuntansi baru di Business Support — pendapatan ter-booking per **Customer + Unit** (deferred revenue). Dibangun bertahap sesuai permintaan:

1. **Inti modul** — pilih Customer (modul Customer) + Unit (modul Unit); jurnal deferred **Dr Piutang Usaha / Cr Pendapatan Diterima Dimuka (Uang Muka Penjualan 20106060601)**; aksi **Akui Pendapatan** (Dr Deferred / Cr Penjualan) & **Batal**. *(migrasi `..._000110_booked_revenue.php`)*
2. **Mode massal** — 1 customer, banyak unit via **checklist** (search + pilih semua), tiap unit → 1 record; atomik (`bookMany`).
3. **Tombol "Pilih Unit Rumah"** → modal hanya menampilkan unit yang **belum pernah di-Booked Revenue** (mengecualikan status booked/recognized; cancelled boleh ulang).
4. **Kolom Diskon/Free DP** + **Nilai Revenue = (Harga − Diskon) ÷ 1,11** (DPP, ex-PPN 11%), dihitung **otoritatif di server**; harga & diskon disimpan (`gross_price`, `discount` — migrasi `..._000120_booked_revenue_discount.php`).
5. **Perbaikan tampilan** — input Diskon pakai pemisah ribuan + font `tabular-nums` (angka 0 tak terbaca seperti 8); kolom Harga & Nilai Revenue juga `tabular-nums` (di Create, Show, Index).
6. **Jurnal manual** — menggantikan jurnal otomatis: **3 baris debit + 3 baris kredit**, COA terhubung General Ledger, **1 jurnal untuk seluruh booking** (semua record terhubung), prefill dari hitungan (Piutang/Deferred/PPN) tapi bisa diedit, indikator **Balance**, validasi `JournalService` (Dr=Cr, ≥2 baris), atomik.
7. **Pemilih COA dapat dicari** — pakai komponen `SearchSelect` (ketik nama/kode), bukan dropdown biasa.

**File**: `BsBookedRevenue` (model), `BookedRevenueService` (book/bookMany/recognize/cancel), `BookedRevenueController`, `Pages/BusinessSupport/BookedRevenue/{Index,Form,Show}.vue`.

### Fase K — Proyek Terkait: Dashboard Exsum (Firebase)

Proyek **terpisah** `Exsum` (`exsum-mup.web.app`) — dashboard Ringkasan Eksekutif per departemen (Firebase Hosting + Firestore, auth client-side MVP).

- Permintaan: user departemen hanya boleh melihat **grafik departemennya sendiri** di Home.
- Solusi (1 file `public/dashboard-auth.js`): petakan tiap kanvas grafik Home → `dept-N`, sembunyikan grafik di luar `allowedModules()` user (admin = semua). Konsisten dengan gating kartu menu yang sudah ada.
- Diverifikasi via tes DOM-stub (Sales/KPR/multi/admin). **Deploy dilakukan user sendiri** (`firebase deploy --only hosting`).

---

## 5. Daftar Bug yang Ditemukan & Diperbaiki

| # | Bug | Konteks | Perbaikan |
|---|-----|---------|-----------|
| 1 | Budget `store/update` "Undefined array key cost_center_id" | uji siklus | `$v['cost_center_id'] ?: null` → `($v['cost_center_id'] ?? null) ?: null` |
| 2 | CIF print/excel error: `id_type` enum (IdType) dilempar ke `e(?string)` | render-test CIF | helper `e()` di-hardening unwrap `BackedEnum` + normalisasi `id_type` di `gather()` |
| 3 | `vue-tsc` tolak `transform((d) => …)` implicit any | build Booked Revenue | dianotasi `(d: any)` |

> Catatan non-bug yang diklarifikasi: Tutup Buku "0 closing journals" (perilaku benar — bulanan hanya lock, tahunan `closeYear()` yang posting L/R); volume timeline CIF tinggi (data demo terakumulasi, ditangani via filter chip + scroll).

---

## 6. Aset Dokumentasi yang Dihasilkan

```
docs/
├── PRD-Java-Core-System.{md,html,doc,pdf}        # PRD lengkap
├── Spesifikasi-Modul-Detail.{md,html,doc,pdf}    # 44 modul detail
├── Diagram-Alur-Modul.{md,html,doc,pdf}          # 40+ flowchart Mermaid
├── Ringkasan-Eksekutif.{md,html,doc,pdf}         # executive brief
├── README.md · _build.php · _schema.txt · _diagrams.md
└── Riwayat-Pengembangan/                          # ← dokumen ini (backup riwayat)

deploy/  + DEPLOY.md + MULAI-DISINI.md + .env.production.example   # paket deployment
```

---

## 7. Status Verifikasi Terkini

| Pemeriksaan | Hasil |
|-------------|-------|
| `npm run build` (vue-tsc + Vite) | ✅ sukses |
| `php artisan smoke:pages` | ✅ **171 / 171 halaman render OK** |
| `php artisan trial:all` | ✅ 24 trial |
| Jurnal GL seluruh transaksi | ✅ balanced (Dr = Cr) |
| Total migrasi diterapkan | 74 |

---

## 8. Lampiran — File & Migrasi Kunci

**Migrasi batch (12 terakhir, semua diterapkan):**

```
2026_06_03_000010_create_contract_operations.php
2026_06_03_000020_create_incentives.php
2026_06_03_000030_add_category_code_to_bs_accounts.php
2026_06_03_000040_add_journal_dimensions.php
2026_06_03_000050_add_unit_customer_to_ap_invoices.php
2026_06_03_000060_advance_settlement_and_petty_cash.php
2026_06_03_000070_fixed_asset_disposal.php
2026_06_03_000080_batch2_po_spk.php
2026_06_03_000090_batch3_budget_workflow.php
2026_06_03_000100_spk_progress_mutation.php
2026_06_03_000110_booked_revenue.php
2026_06_03_000120_booked_revenue_discount.php
```

**Layanan inti Business Support** (`app/Domain/BusinessSupport/Services/`):
`JournalService`, `BookedRevenueService`, `CogsService`, `ContractorService`, `FixedAssetService`, `PayrollService`, `GiroService`, `CashAdvanceService`, `PeriodClosingService`, `Ap/ArKuitansiService`, `Ap/ArMemorialService`, `StockService`.

**Service domain Master**: `CustomerActivityService` (timeline 360°).

**Komponen UI reusable**: `SearchSelect.vue`, `Button`, `Badge`, `EmptyState`, `Input`, `RegionSelect`, `AddressFields`.

---

*Dokumen ini adalah backup ringkasan. Untuk detail teknis tiap modul, lihat `docs/Spesifikasi-Modul-Detail.*` dan `docs/Diagram-Alur-Modul.*`.*
