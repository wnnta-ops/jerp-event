# 06 — Rencana Fase Implementasi Modul BO (Back Office / Accounting)

**eProperty (Clone) — Property Developer ERP · Target Laravel 11/12 · Panel Filament 3 (`/bo`)**
Companion: [00_README.md](00_README.md) · [02_PRD.md](02_PRD.md) · [03_ERD.md](03_ERD.md) · [04_BusinessFlow.md](04_BusinessFlow.md) · [05_NFR_dan_Kebutuhan_Teknis.md](05_NFR_dan_Kebutuhan_Teknis.md)

> Dokumen ini memecah pembangunan **Modul BO** menjadi fase berurutan yang dapat dikerjakan dan diuji satu per satu (*incremental, vertical slice*). Setiap fase punya **tujuan, deliverable konkret (file), dependensi, dan acceptance criteria**. Urutan fase mengikuti **dependensi data**: General Ledger (GL) adalah fondasi — semua transaksi keuangan bermuara ke jurnal GL.

---

## ✅ Status Implementasi (per 2026-05-31)

Seluruh 11 fase (0–10) **telah diimplementasi & diuji**. Ringkasan:

| Fase | Status | Bukti |
| --- | --- | --- |
| 0 Fondasi | ✅ | `NumeratorService` atomic (nyata, bukan stub), `Auditable`, kontrak GL, navigasi panel `bo`, `BoPermissionsSeeder` |
| 1 General Ledger | ✅ | `bo_chart_of_accounts/jurnal_header/jurnal_detil/periods`, `JurnalPostingService`, COA seeder, `ChartOfAccountResource` + `JurnalResource` — **6 test** |
| 2 Cash & Bank | ✅ | `bo_cb_vouchers`, `CbVoucherService`, `CbVoucherResource` — **3 test** |
| 3 Accounts Receivable | ✅ | `bo_ar_invoices`, `ArInvoiceService` + `ArAgingService`, resource + Aging page — **4 test** |
| 4 Accounts Payable | ✅ | `bo_suppliers/ap_invoices`, `ApInvoiceService` (PPN/PPh + bayar via BKK), `ApAgingService` — **2 test** |
| 5 Inventory | ✅ | `bo_ivt_*`, `InventoryService` (stok atomic row-lock, FPB approval) — **5 test** |
| 6 Purchase Request → PO | ✅ | `bo_purchase_requests/pr_lines/purchase_orders/po_lines`, two-step approval + convert PO — **3 test** |
| 7 HR | ✅ | `bo_hr_dept/karyawan`, resources |
| 8 FOBO Bridge | ✅ | `bo_fobo_postings` (idempoten), `FoFinancialEvent` + `PostFoEventToGl` (queue `sync`), `config/fobo.php`, emit dari `TtsToBkmService` — **3 test** |
| 9 Closing & Laporan | ✅ | `PeriodClosingService`, `FinancialReportService` (Neraca/L-R/Trial Balance), pages + widget, cetak voucher Blade/Browsershot — **4 test** |
| 10 Hardening | ✅ | Pint + PHPStan (Larastan) + **30 test BO hijau** |

**Total: 30 feature test BO lulus (78 assertions).** Diverifikasi via container `jerp/app` (SQLite in-memory) + `migrate:fresh --seed` bersih + 43 route panel `bo` ter-register.

> Catatan: keputusan `DECIMAL(18,2)` (selaras FO) diterapkan; `NumeratorService` yang sebelumnya stub kini diimplementasi penuh (atomic row-lock) — ini juga memperbaiki penomoran FO.

---

## 0. Status Saat Ini (per 2026-05-30)

| Aspek | Status |
| --- | --- |
| `app/Modules/BO/{Controllers,Models,Services,Events,Listeners,Policies,Requests,DataTransferObjects}/` | ✅ Folder ada (hanya `.gitkeep`, **belum ada kode**) |
| `BoPanelProvider` (panel `bo`, path `/bo`) | ✅ Terdaftar; auto-discover `app/Filament/Bo/Resources` & `Pages` & `Widgets` (folder belum ada) |
| `config/modules.php` → `bo` | ✅ `enabled: true`, `panel: bo` |
| Migrasi `bo_*` | ❌ Belum ada satupun |
| `NumeratorService` + tabel `numerators` | ✅ Sudah ada (dipakai juga FO) |
| `TenantAwareModel` (global scope + auto `tenant_id`) | ✅ Ada di `app/Models/Concerns/TenantAwareModel.php` |
| Core masters (`companies`, `cost_centers`, `banks`, `projects`) | ✅ Migrasi sudah ada |
| FO `Events`/`Listeners` | ⚠️ **Folder kosong** — belum ada event domain FO. **Blocker untuk FOBO (Fase 8)** |
| Pola acuan | Filament FO Resources (mis. `app/Filament/Fo/Resources/BkmResource.php`) + migrasi `fo_*` |

**Konvensi yang WAJIB diikuti (dari [CLAUDE.md](../CLAUDE.md)):**
- Semua model transaksi extend `TenantAwareModel`, pakai `SoftDeletes`, kolom audit `created_by`/`updated_by`/`deleted_by`.
- Uang = **`DECIMAL(18,2)`** — **diselaraskan dengan FO existing** (keputusan 2026-05-30, menimpa ERD §9 yang menyebut `19,4`) agar seragam & tanpa rounding di boundary FOBO.
- Penomoran lewat `NumeratorService::next(jenis, companyId)` — **dilarang `MAX()+1`**.
- Business logic di **Service**, bukan Model/Resource. Controller/Resource tipis.
- Sync antar-modul via **Event → Listener (queue `sync`)**, bukan call Model langsung.
- Sebelum commit tiap fase: `./vendor/bin/pint` + `./vendor/bin/phpstan analyse` + `php artisan test` (= `composer check`).

---

## 1. Peta Jalan (Roadmap) Ringkas

```
Fase 0  Fondasi BO (scaffolding, kontrak posting, navigasi, permission, seeder COA dasar)
Fase 1  General Ledger (GL)        ← FONDASI: COA, Jurnal, JurnalPostingService, approval, posting
Fase 2  Cash & Bank (CB)           ← voucher BKM/BKK/BGM/BGK/BNG/BS/ADM/ANO/PBS + rekon bank
Fase 3  Accounts Receivable (AR)   ← invoice piutang (mirror FO), denda, aging, BPD
Fase 4  Accounts Payable (AP)      ← supplier, invoice PO/NONPO, kuitansi, TTG, BPPH, aging
Fase 5  Inventory (IVT)            ← item, movement BBB/BKB/BPB/FPB, price list, adjustment
Fase 6  Purchase Request (PR→PO)   ← PR → approval → PO, RFQ, cost sheet
Fase 7  HR (ringkas)               ← departemen, karyawan, BRQ
Fase 8  FOBO Bridge                ← Event FO → Listener → posting jurnal otomatis (idempotent)
Fase 9  Closing & Laporan Keuangan ← tutup periode, amortisasi, Neraca/L-R/Arus Kas, PDF voucher
Fase 10 Modul ringan & Hardening   ← LAND/LEASE/SAL/LGL (opsional), multi-company, audit, go-live
```

**Critical path:** Fase 0 → 1 → 8 (FOBO) adalah jalur nilai bisnis utama (otomatisasi pembukuan dari FO). Fase 2–7 dapat sebagian diparalelkan setelah Fase 1 selesai, tetapi semuanya bergantung pada `JurnalPostingService` (Fase 1).

---

## Fase 0 — Fondasi & Scaffolding BO

**Tujuan:** Menyiapkan kerangka modul BO yang reusable agar fase berikutnya tinggal isi domain.

**Deliverable:**
1. Struktur sub-modul di `app/Modules/BO/` (buat sub-folder per area, masing-masing dengan `Models/`, `Services/`, `Requests/`, `Events/`, `Listeners/`, `Policies/`, `DataTransferObjects/`):
   - `GeneralLedger/`, `CashBank/`, `AccountsReceivable/`, `AccountsPayable/`, `Inventory/`, `PurchaseRequest/`, `HR/`, `FoBoBridge/`, `Setup/`
2. **Kontrak posting GL** (interface) di `app/Modules/BO/GeneralLedger/Contracts/`:
   - `PostsToGeneralLedger` — DTO standar baris jurnal (`JurnalLineDTO`) + method `toJurnalLines(): array`.
   - `JurnalPostingServiceInterface` (di-bind di Fase 1).
3. **Navigasi panel BO**: tambahkan `navigationGroups()` di `BoPanelProvider` (GL, Cash & Bank, AR, AP, Inventory, Purchasing, HR, Laporan, Setup) agar resource fase berikut tinggal `->navigationGroup(...)`.
4. **Trait `Auditable`** (jika belum ada global) untuk auto-isi `created_by`/`updated_by` — verifikasi apakah sudah dihandle `TenantAwareModel`; jika belum, buat `app/Models/Concerns/Auditable.php`.
5. **Permission scaffolding**: tambah grup permission `bo.*` ke seeder (`bo.gl.jurnal.{view,create,approve,post,cancel}`, `bo.cb.voucher.*`, dst.) + role `accounting`, `finance_manager`.
6. **`bootstrap/providers.php`** / `ModuleServiceProvider`: pastikan binding service BO ter-register (buat `app/Modules/BO/Providers/BoServiceProvider.php` jika perlu untuk DI interface→implementasi).

**Dependensi:** —
**Acceptance:** `php artisan about` jalan tanpa error; panel `/bo` login & tampil dashboard kosong dengan navigation group; `phpstan` & `pint` hijau.

---

## Fase 1 — General Ledger (GL) [FONDASI]

> Referensi ERD §6: `bo_chart_of_accounts`, `bo_jurnal_header`, `bo_jurnal_detil`. Status transition §8: `DRAFT → APPROVED → POSTED; → CANCELLED`.

**Tujuan:** Buku besar yang balance-enforced + Chart of Account, sebagai tulang punggung seluruh posting.

**Deliverable:**

*Migrasi* (`database/migrations/`, kronologis setelah core masters):
- `create_bo_chart_of_accounts_table` — `kode` (uniq per tenant), `nama`, `tipe_akun` enum(ASSET,LIABILITY,EQUITY,REVENUE,EXPENSE), `level`, `parent_kode`, `is_pajak`, `is_header`, `is_active`.
- `create_bo_jurnal_header_table` — `no_jurnal` (uniq/tenant), `tgl_jurnal`, `company_id`, `jenis_dokumen`, `no_dokumen`, `status` enum(DRAFT,APPROVED,POSTED,CANCELLED), `posted` bool, `total_debet`/`total_kredit` (18,2), `keterangan`, audit cols, softDeletes.
- `create_bo_jurnal_detil_table` — `jurnal_id` FK, `urutan`, `kode_account`, `cost_center_id` FK nullable, `debet`/`kredit` DECIMAL(18,2), `keterangan`. Index `(jurnal_id)`, `(kode_account)`.

*Models* (`app/Modules/BO/GeneralLedger/Models/`):
- `ChartOfAccount`, `JurnalHeader` (hasMany `detil`, scope `posted`/`periode`), `JurnalDetil` (belongsTo coa, costCenter).

*Services* (`app/Modules/BO/GeneralLedger/Services/`):
- `JurnalPostingService implements JurnalPostingServiceInterface`:
  - `post(PostsToGeneralLedger $source, array $meta): JurnalHeader` — bangun header+detil dalam **DB transaction**, **validasi balance** (`Σdebet == Σkredit`, > 0), assign `no_jurnal` via `NumeratorService::next('JU', companyId)`, status awal sesuai config (auto-post vs perlu approval).
  - `approve(JurnalHeader $j)`, `cancel(JurnalHeader $j)` (buat jurnal pembalik bila sudah POSTED — bukan hard delete), `reverse(JurnalHeader $j)`.
  - Guard: tidak boleh edit/cancel jurnal di periode tertutup (cek Fase 9; sementara stub).
- DTO `JurnalLineDTO` (kode_account, cost_center_id, debet, kredit, keterangan).

*Filament* (`app/Filament/Bo/Resources/`):
- `ChartOfAccountResource` — tree/list COA, form CRUD, guard tidak hapus akun yang sudah dipakai.
- `JurnalResource` — list + form dengan **Repeater detil**, live total debet/kredit + indikator balance, Action `Approve`/`Post`/`Reverse` (panggil Service, authorize policy).

*Seeder:* `BoChartOfAccountSeeder` — COA standar developer properti (Kas, Bank, Piutang KPR, Piutang Customer, Persediaan Unit/Tanah, Hutang, Modal, Pendapatan Penjualan, PPN Keluaran, HPP, Beban Komisi, dll.).

*Numerator:* daftarkan jenis `JU` (jurnal umum) + per-jenis-dokumen di tabel `numerators`.

*Policy:* `JurnalPolicy`, `ChartOfAccountPolicy`.

*Tests* (`tests/Feature/BO/`):
- `it_posts_a_balanced_jurnal`, `it_rejects_unbalanced_jurnal`, `it_generates_sequential_no_jurnal_atomically` (uji konkurensi), `it_reverses_a_posted_jurnal`, `it_blocks_edit_on_closed_period` (stub).

**Dependensi:** Fase 0, `companies`, `cost_centers`, `NumeratorService`.
**Acceptance:** Jurnal manual bisa dibuat–approve–post di `/bo`; jurnal tidak balance ditolak; nomor jurnal atomic & berurutan; `composer check` hijau. **Ini gerbang wajib sebelum Fase 2–9.**

---

## Fase 2 — Cash & Bank (CB)

> ERD §6 `bo_cb_vouchers`. Legacy: `ADMRegis`, `ANOIdent`, `BGKFile`, `RekonBank`.

**Tujuan:** Voucher kas/bank + rekonsiliasi, tiap voucher posting ke GL.

**Deliverable:**
- Migrasi `create_bo_cb_vouchers_table` — `jenis` enum(BKM,BKK,BGM,BGK,BNG,BS,ADM,ANO,PBS), `no_voucher` (uniq/tenant), `tgl`, `company_id`, `bank_id`, `akun_lawan` (kode COA), `nominal` (18,2), `status` enum(DRAFT,POSTED,BATAL), `pihak`, `keterangan`, audit, softDeletes. + (opsional) `bo_bank_reconciliations`.
- Model `CbVoucher implements PostsToGeneralLedger` (mapping jenis → pasangan debet/kredit COA).
- Service `CbVoucherService` (registrasi, posting via `JurnalPostingService`, void = pembalik), `BankReconciliationService`.
- Filament `CbVoucherResource` (filter per jenis, Action Post/Void), `BankReconciliationPage`.
- Numerator per jenis (BKM, BKK, BGM, BGK, BNG, BS, ADM, ANO, PBS).
- Reimbursement (PBS) & refund flow.
- Tests: posting tiap jenis menghasilkan jurnal balance; void membuat pembalik; rekon mencocokkan saldo.

**Dependensi:** Fase 1, `banks`.
**Acceptance:** Tiap jenis voucher posting jurnal benar; rekonsiliasi bank jalan; numerator per-jenis benar.

---

## Fase 3 — Accounts Receivable (AR)

> ERD §6 `bo_ar_invoices` (mirror FO via FOBO). Legacy: `FIRegis`, `FK`, `FP`, `QueryAging`, `BatchFP`.

**Tujuan:** Piutang & aging di sisi accounting (sebagian besar **mirror** dari FO, diisi otomatis lewat FOBO di Fase 8; di fase ini sediakan struktur + entri manual + laporan).

**Deliverable:**
- Migrasi `create_bo_ar_invoices_table` — `no_invoice`, `jenis` (FI/FK/FP), `customer_id` (natural-key/mirror), `kontrak_ref`, `nominal` (18,2), `nominal_bayar`, `sisa`, `tgl`, `jatuh_tempo`, `status` enum(BELUM,SEBAGIAN,LUNAS,BATAL). (+ `bo_ar_denda`, `bo_ar_addendum` bila perlu).
- View/laporan **aging** (query service, bukan tabel; `AgingService`).
- Model `ArInvoice`, Service `ArInvoiceService` (+ denda otomatis hook), `ArAgingService`.
- Filament `ArInvoiceResource` (read-heavy + Action denda/addendum), `ArAgingPage` (bucket 0–30/31–60/61–90/>90).
- Tests: aging bucketing benar; denda terhitung; status piutang transisi benar.

**Dependensi:** Fase 1. (Pengisian massal nyata via Fase 8 FOBO + sync FO.)
**Acceptance:** Aging report akurat untuk dataset uji; invoice manual bisa dibuat & posting ke GL.

---

## Fase 4 — Accounts Payable (AP)

> ERD §6 `bo_suppliers`, `bo_ap_invoices`. Legacy: `InvoicePORegis`, `BatchINV`, `BatchBPPH`, `BTBPosting`.

**Tujuan:** Hutang usaha: supplier, invoice PO & Non-PO, kuitansi, memorial, TTG, BPPH, posting & aging.

**Deliverable:**
- Migrasi: `create_bo_suppliers_table` (`kode`, `nama`, `npwp`, `alamat`, `bank_account`), `create_bo_ap_invoices_table` (`no_invoice`, `supplier_id`, `jenis` PO/NONPO, `nominal`, `ppn`, `pph`, `status`, `jatuh_tempo`, ref PO Fase 6), + (opsional) `bo_ap_payments`/TTG.
- Model `Supplier`, `ApInvoice implements PostsToGeneralLedger`. Service `ApInvoiceService` (registrasi, approval, posting hutang→GL, pembayaran via CB), `ApAgingService`.
- Filament `SupplierResource`, `ApInvoiceResource` (Action Post, Bayar→buat CB voucher BKK), `ApAgingPage`.
- TTG (Tanda Terima Giro), BPPH, memorial sebagai jenis/aksi.
- Numerator: INV-AP, TTG, BPPH.
- Tests: posting hutang balance; PPN/PPh terpisah ke COA pajak; pembayaran mengurangi sisa; aging benar.

**Dependensi:** Fase 1; integrasi ke CB (Fase 2) untuk pembayaran; PO (Fase 6) untuk invoice PO (boleh nullable dulu).
**Acceptance:** Siklus invoice→approve→post→bayar utuh; pajak benar; aging hutang akurat.

---

## Fase 5 — Inventory (IVT)

> ERD §6 `bo_ivt_items`, `bo_ivt_movements`. Legacy: `BKB`, `BPB`, `FPB`, `PL`.

**Tujuan:** Stok material/barang + pergerakan + price list + adjustment.

**Deliverable:**
- Migrasi: `create_bo_ivt_categories_table`, `create_bo_ivt_locations_table`, `create_bo_ivt_items_table` (`kode`, `nama`, `kategori_id`, `satuan`, `stok`, `harga_pokok`), `create_bo_ivt_movements_table` (`jenis` BBB/BKB/BPB/FPB, `item_id`, `qty`, `lokasi_id`, `tgl`, `ref_dokumen`, `nilai`).
- Model `IvtItem`, `IvtMovement`, `IvtCategory`, `IvtLocation`. Service `InventoryService` (mutasi stok atomic via row-lock, valuasi, adjustment, posting persediaan→GL).
- Filament resources: `IvtItemResource`, `IvtMovementResource` (FPB dengan approval), `PriceListResource`, `StockAdjustmentPage`.
- Tests: stok bertambah/berkurang konsisten saat mutasi; FPB approval; valuasi benar; movement posting GL.

**Dependensi:** Fase 1.
**Acceptance:** Mutasi stok akurat & atomic; FPB approval jalan; valuasi persediaan posting GL benar.

---

## Fase 6 — Purchase Request → PO (PR)

> ERD §6 `bo_pr_purchase_requests`. PRD §3.2: PR → approval → PO, RFQ, cost sheet.

**Tujuan:** Alur pengadaan dari permintaan hingga PO, feeding AP & IVT.

**Deliverable:**
- Migrasi: `create_bo_pr_purchase_requests_table` (`no_pr`, `dept_id`, `status` DRAFT/APPROVED/REJECTED/PO, `keterangan`), `create_bo_pr_lines_table`, `create_bo_purchase_orders_table` (+ lines), (opsional) `bo_rfq`, `bo_cost_sheets`.
- Model + Service `PurchaseRequestService` (approval workflow two-step), `PurchaseOrderService` (PR→PO konversi).
- Filament: `PurchaseRequestResource` (Action Approve/Reject/ConvertToPO), `PurchaseOrderResource`.
- Numerator: PR, PO.
- Tests: PR approval two-step; konversi PR→PO; PO terhubung ke AP invoice (Fase 4) & penerimaan barang (IVT Fase 5).

**Dependensi:** Fase 1; integrasi opsional AP (4) & IVT (5); `bo_hr_dept` (Fase 7, boleh nullable dulu).
**Acceptance:** Siklus PR→approve→PO utuh; PO bisa ditarik saat input invoice AP & penerimaan IVT.

---

## Fase 7 — HR (ringkas)

> ERD §6 `bo_hr_karyawan`, `bo_hr_dept`. PRD §3.2: karyawan, departemen, BRQ.

**Tujuan:** Master HR minimal yang dibutuhkan modul lain (dept untuk PR, karyawan untuk approval/cost center).

**Deliverable:**
- Migrasi: `create_bo_hr_dept_table` (`kode`, `nama`), `create_bo_hr_karyawan_table` (`nik`, `nama`, `dept_id`, `jabatan`, `status`). (BRQ = opsional.)
- Model + Filament `DepartemenResource`, `KaryawanResource`.
- Tests: CRUD + relasi dept↔karyawan.

**Dependensi:** Fase 0. (Bisa dikerjakan paralel; idealnya sebelum Fase 6 final agar `dept_id` non-null.)
**Acceptance:** Master HR siap dipakai PR & cost center.

---

## Fase 8 — FOBO Bridge (Posting Otomatis FO → BO) [NILAI INTI]

> ERD §6 `bo_fobo_postings`. PRD §3.2 FOBO: `KasirJual`, `PencairanAkad(Sync)`, `Buyback`, `RefundLebihBayar`. Legacy: stored proc `SP_FOtoStandAlone_*`.

**⚠️ Blocker:** Folder `app/Modules/FO/Events` & `Listeners` **masih kosong**. Fase ini **wajib** dimulai dengan menambahkan **event domain di FO** terlebih dulu (atau emit dari Service FO yang ada).

**Tujuan:** Setiap kejadian keuangan di FO otomatis menghasilkan jurnal GL yang balance, idempotent, dan tertelusur.

**Deliverable:**
1. **Event FO** (`app/Modules/FO/.../Events/`): `KasMasukDicatat` (BKM/TTS), `KontrakClosing` (KasirJual/sales), `MemoDibuat` (cashback/refund), `KomisiDibayar`, `PencairanAkad` (dari BankLoan), `BuybackDiposting`, `RefundLebihBayar`. Emit dari Service terkait (mis. `TtsToBkmService`).
2. **Tabel jejak** `create_bo_fobo_postings_table` — `source_modul`, `source_no`, `jenis` enum(KAS_MASUK,SALES,KOMISI,BUYBACK,REFUND,AKAD), `jurnal_id` FK, `status`, `posted_at`, **unique(`source_modul`,`source_no`,`jenis`)** untuk idempotensi.
3. **Listeners** (`app/Modules/BO/FoBoBridge/Listeners/`, `implements ShouldQueue`, `public string $queue = 'sync'`, `ShouldBeUnique`): tiap listener memetakan event → set baris jurnal → panggil `JurnalPostingService::post()`, catat `bo_fobo_postings`. Skip jika `source` sudah pernah diposting (idempotent).
4. **Mapping akun** terkonfigurasi (tabel/`config` `fobo_account_mapping`): jenis transaksi → pasangan COA debet/kredit (mudah diubah accounting tanpa deploy).
5. Filament `FoboPostingResource` (monitor, retry gagal, lihat jurnal hasil).
6. Tests: tiap event memicu jurnal benar & balance; **replay event tidak menduplikasi** (idempotent); listener gagal bisa retry; mapping akun terbaca.

**Dependensi:** Fase 1 (wajib), Fase 2 (CB), Fase 3 (AR); event FO. Horizon queue `sync` aktif.
**Acceptance:** Buat kontrak/BKM/memo/komisi di FO → jurnal GL muncul otomatis & balance; replay aman; dashboard FOBO menampilkan status posting.

---

## Fase 9 — Closing Periode, Amortisasi & Laporan Keuangan

> PRD §3.2 GL: `ClosingPT`, amortisasi, `QueryBS`, `QueryCashFlow`. Legacy: `JurnalRegis`, `ClosingPT1..2`.

**Tujuan:** Tutup buku periode + laporan keuangan standar + cetakan voucher.

**Deliverable:**
- Migrasi: `create_bo_periods_table` (`company_id`, `tahun`, `bulan`, `status` OPEN/CLOSED, `closed_by`, `closed_at`), (opsional) `bo_amortization_schedules`.
- Service `PeriodClosingService` (validasi semua jurnal periode POSTED, lock periode, jurnal penutup laba-rugi), `AmortizationService`, `FinancialReportService` (Neraca, Laba-Rugi, Arus Kas, Trial Balance dari `bo_jurnal_detil`).
- **Aktifkan guard** di `JurnalPostingService`: tolak posting/edit pada periode CLOSED (lengkapi stub Fase 1).
- Filament Pages: `NeracaPage`, `LabaRugiPage`, `ArusKasPage`, `TrialBalancePage`, `ClosingPeriodePage` + widget dashboard (saldo kas/bank, ringkasan L-R bulan berjalan).
- **PDF voucher** (`resources/views/pdf/bo/*.blade.php`): INV, KUI, MEMO, TTG, BKM, BKK, BGM, BGK, BS (Browsershot) — visual-diff + sign-off operator (PRD §5).
- Tests: trial balance selalu balance; closing mengunci periode & menolak posting mundur; laporan keuangan cocok dengan agregat jurnal.

**Dependensi:** Fase 1–8.
**Acceptance:** Tutup buku 1 periode end-to-end; Neraca/L-R/Arus Kas benar & balance; voucher cetak lulus sign-off.

---

## Fase 10 — Modul Ringan, Multi-Company & Hardening (Go-Live BO)

> PRD §3.2: LAND, LEASE, SAL, LGL (status sebagian **dormant** — lihat [01_BRD.md §8](01_BRD.md) "modul BO dormant"). PRD §6 Acceptance Criteria go-live.

**Tujuan:** Lengkapi modul opsional bila diputuskan aktif, lalu hardening lintas-modul untuk go-live.

**Deliverable:**
- **(Opsional, sesuai keputusan)** LAND (land bank: akuisisi/transfer ledger), LEASE, SAL, LGL — implementasi tipis bila tidak dormant.
- **Multi-company & multi-cost-center** diuji menyeluruh (scope `forCompany()` + filter cost center di semua laporan).
- **Authorization** lengkap: policy per Resource, permission `bo.*` ter-seed, 2FA untuk `finance_manager`.
- **Audit log** (Spatie Activitylog) aktif di semua aksi tulis BO.
- **Data migration**: artisan command `migrate:legacy` untuk COA, saldo awal, jurnal historis (subset → full) + skrip rekonsiliasi (row count + Σ nominal) per ERD §11 / NFR §Migrasi.
- **Performa**: indeks sesuai ERD §10; query laporan teruji terhadap target NFR (doc 05).
- QA regresi penuh + dokumentasi operator.

**Dependensi:** Fase 1–9.
**Acceptance:** Seluruh checklist [02_PRD.md §6](02_PRD.md) terpenuhi untuk BO; reconciliation migrasi cocok; security & audit lulus.

---

## 2. Matriks Dependensi & Paralelisasi

| Fase | Bergantung pada | Bisa paralel dengan | Blocker eksternal |
| --- | --- | --- | --- |
| 0 Fondasi | — | — | — |
| 1 GL | 0 | — | — |
| 2 CB | 1 | 3,4,5,7 | — |
| 3 AR | 1 | 2,4,5,7 | (data nyata: Fase 8) |
| 4 AP | 1 (+2 utk bayar) | 3,5,7 | PO Fase 6 (nullable dulu) |
| 5 IVT | 1 | 2,3,4,7 | — |
| 6 PR→PO | 1 (+4,5,7) | — | dept Fase 7 |
| 7 HR | 0 | 2,3,4,5 | — |
| 8 FOBO | 1,2,3 | — | **Event FO belum ada** |
| 9 Closing/Laporan | 1–8 | — | — |
| 10 Hardening | 1–9 | — | keputusan modul dormant (BRD §8) |

---

## 3. Definition of Done per Fase (checklist)

- [ ] Migrasi reversible (`down()` lengkap), `migrate:fresh --seed` hijau.
- [ ] Model: `TenantAwareModel` + `SoftDeletes` + audit cols + relasi + cast `decimal:4` untuk uang.
- [ ] Business logic di **Service**, bukan di Resource/Model.
- [ ] Penomoran via `NumeratorService` (tidak ada `MAX()+1`) + uji konkurensi.
- [ ] Filament Resource + Policy + permission ter-seed.
- [ ] Setiap transaksi keuangan **posting jurnal balance** lewat `JurnalPostingService`.
- [ ] Test: happy-path + ≥1 edge case (Pest, `tests/Feature/BO/` + `tests/Unit/BO/`).
- [ ] `./vendor/bin/pint` + `./vendor/bin/phpstan analyse` (Larastan lvl 8) + `php artisan test` hijau (`composer check`).
- [ ] String UI di `lang/id/`; istilah domain tetap Indonesia.

---

## 4. Risiko & Pertanyaan Terbuka (perlu keputusan sebelum mulai)

1. **Event FO belum ada** → Fase 8 butuh penambahan event di modul FO. Perlu koordinasi siapa yang menambahkan emit (tim FO vs BO). **Rekomendasi:** tambahkan event minimal di Service FO yang relevan saat masuk Fase 8.
2. **Skala uang `DECIMAL`**: ~~ERD §9 menetapkan `(18,2)`~~ → **DIPUTUSKAN (2026-05-30): BO pakai `(18,2)` seragam dengan FO** — tidak perlu rounding di boundary FOBO. (Catatan: deviasi sadar dari ERD §9.)
3. **Modul dormant** (LAND/LEASE/SAL/LGL): aktif atau ditunda? (BRD §8). Mempengaruhi cakupan Fase 10.
4. **Multi-tenant vs multi-company**: BO legacy multi-company dalam 1 tenant. Pastikan `company_id` (operasional) ≠ `tenant_id` (isolasi SaaS) tidak tertukar.
5. **Sumber kebenaran COA**: butuh restore `.bak` legacy untuk COA & saldo awal final (00_README §7) sebelum seeder COA dianggap otoritatif.
6. **e-Faktur / PPN keluaran**: integrasi pajak (AR/AP) — H2H atau manual? (BRD §8).

---

## 5. Urutan Eksekusi yang Direkomendasikan

1. **Fase 0 + 1** dulu sampai benar-benar solid (GL adalah fondasi — jangan lanjut bila balance enforcement & numerator belum teruji).
2. **Fase 2 (CB)** — validasi pola "dokumen → posting GL" pada kasus paling sederhana.
3. **Fase 8 (FOBO)** lebih awal jika prioritas bisnis = otomatisasi pembukuan dari FO (begitu GL+CB siap). Jika tidak, lanjut linear 3→4→5→6→7 lalu 8.
4. **Fase 9** setelah semua sumber jurnal (1–8) lengkap.
5. **Fase 10** untuk go-live.

> Setiap fase = 1 *vertical slice* yang bisa di-demo & di-merge sendiri. Jangan big-bang. Patuhi [CLAUDE.md §7](../CLAUDE.md) untuk langkah penambahan fitur per modul.
