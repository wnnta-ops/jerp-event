# 🗺️ IMPLEMENTATION ROADMAP — Java Core System v2.0

**Project:** Java Core System (Property Developer ERP)
**Owner:** PT. Mitra Usaha Propertindo (MUP)
**Target Project:** Java Residence
**Period:** Q2-Q3 2026
**Status:** Phase 1 (Foundation) selesai — siap masuk Phase 2

---

## 📋 Executive Summary

Java Core System v2.0 adalah rebuild **production ERP** untuk operasional penjualan property Java Residence. Phase 1 (foundation: Laravel + Inertia + Vue + auth + 12 module skeleton) sudah selesai dan **operational**.

Dokumen ini merangkum **roadmap implementasi Phase 2-7** untuk mencapai **full parity dengan Java Core System Lama (vendor: Batavianet)** sebelum cutover.

### 🎯 Goal
- **Parity penuh** dengan sistem lama untuk operasional sales/finance/legal
- **Modernisasi UX/security** (SPA, dark mode, MFA, audit log, dll)
- **Migration-ready** — bisa import data customer/booking/kontrak/pembayaran dari sistem lama

### 📊 Hasil Gap Analysis (vs sistem lama)

| Kategori | Status Phase 1 | Gap untuk Go-Live |
|----------|---------------|-------------------|
| Modul coverage | 12/14 parity | 2 (Leasing - skip per scope) |
| Customer model | 15 field | +30 field (sections lengkap) |
| Master entities | 8 entities | +3 missing (PaymentSchemes, DataSources, GimmickPackages) |
| Payment system | Basic TTS+Kuitansi | +Memo + PaymentAllocation source_type + Free DP/Subsidi |
| PPJB lifecycle | Generic status | Dedicated 4-state + dual numbering + document checklist |
| Gimmick (promo) | ❌ Tidak ada | Full module (Package + Item + ContractGimmick) |
| Calculator | ❌ Tidak ada | BPHTB + KPR + Skema |
| Migration tools | ❌ Tidak ada | Bulk Excel import (4 jenis data) |
| Audit log | Backend ada, UI no | Per-record viewer + activity log |
| Custom error pages | Default Laravel | Indonesian friendly (Restricted/Deleted) |

**Total estimated effort: 7-8 minggu** (sprint of 2 minggu masing-masing).

---

## 🚦 Phasing Overview

```
Phase 1 ─────── DONE ── Foundation (Laravel + Inertia + Vue + 12 modules)
   │
Phase 2 ─────── 1 minggu ── Customer Expansion + Data Sources + Address Structuring
   │
Phase 3 ─────── 1-2 minggu ── Payment Foundation (Enums + Allocation + Memo + Free DP)
   │
Phase 4 ─────── 1 minggu ── Contract Lifecycle (PPJB state + Documents + Conditional tabs)
   │
Phase 5 ─────── 1 minggu ── Gimmick Module (Package + Item + ContractGimmick)
   │
Phase 6 ─────── 1 minggu ── Calculators + UX Quick Wins (BPHTB/KPR/Skema + Audit Log + Prev/Next)
   │
Phase 7 ─────── 1-2 minggu ── Migration Tools + Custom Errors + Cutover Prep
   │
GO-LIVE ────────────────── Parity dengan Java Core System Lama
```

---

## 📦 Phase 2: Customer Expansion + Foundation Masters (1 minggu)

**Dependencies:** None — bisa langsung mulai
**Output:** Customer 7-section form + 30 field tambahan + 2 master baru

### Tasks

| # | Task | Effort | Files Affected | Acceptance |
|---|------|--------|----------------|------------|
| 2.1 | Migration `customers` table: tambah 30 field structured | 4h | `database/migrations/*_expand_customers.php` | Test pass |
| 2.2 | Customer model update + casts + accessors | 2h | `app/Models/Customer.php` | Existing tests pass |
| 2.3 | Customer factory + seeder update | 2h | `database/factories/CustomerFactory.php` | Seed berhasil |
| 2.4 | Customer create/edit form Vue (7 sections) | 6h | `resources/js/Pages/Master/Customer/{Create,Edit}.vue` | UI render OK |
| 2.5 | "SAMA dengan KTP" UX toggle component | 1h | `resources/js/Components/UI/AddressClone.vue` | Auto-copy works |
| 2.6 | "Terbaru:" recent customers panel | 2h | `Master/Customer/Create.vue` | Show last 10 |
| 2.7 | Context-aware (`?context=booking`) | 2h | `CustomerController@create` | Redirect after save |
| 2.8 | Master `data_sources` (marketing channels) table + CRUD UI | 4h | Migration + Model + Pages | Seedable |
| 2.9 | Address structuring helper + migration legacy data | 3h | `app/Support/AddressParser.php` | Parse flat → 6 field |
| 2.10 | Religion + Marital Status + Citizenship enums | 1h | `app/Enums/` | Compile clean |
| 2.11 | Customer model test coverage | 2h | `tests/Feature/CustomerTest.php` | All green |

### Schema Changes — Customer table

```sql
ALTER TABLE customers
  -- Identitas
  ADD COLUMN project_id BIGINT NULL REFERENCES projects(id),
  ADD COLUMN data_source_id BIGINT NULL REFERENCES data_sources(id),
  ADD COLUMN nationality ENUM('WNI', 'WNA') DEFAULT 'WNI',

  -- Kartu Identitas (KTP structured)
  ADD COLUMN address_rt_rw VARCHAR(20) NULL,
  ADD COLUMN kelurahan VARCHAR(100) NULL,
  ADD COLUMN kecamatan VARCHAR(100) NULL,
  ADD COLUMN kota VARCHAR(100) NULL,
  ADD COLUMN id_expires_at DATE NULL,
  ADD COLUMN id_lifetime BOOLEAN DEFAULT FALSE,

  -- Data Pribadi
  ADD COLUMN religion ENUM('islam','budha','konghucu','kristen','hindu','katolik','lainnya') NULL,
  ADD COLUMN birth_place VARCHAR(100) NULL,
  ADD COLUMN birth_date DATE NULL,
  ADD COLUMN marital_status ENUM('menikah','cerai','belum_menikah','lain_lain') NULL,

  -- Alamat NPWP
  ADD COLUMN npwp_same_as_ktp BOOLEAN DEFAULT TRUE,
  ADD COLUMN npwp_address VARCHAR(255) NULL,
  ADD COLUMN npwp_rt_rw VARCHAR(20) NULL,
  ADD COLUMN npwp_kelurahan VARCHAR(100) NULL,
  ADD COLUMN npwp_kecamatan VARCHAR(100) NULL,
  ADD COLUMN npwp_kota VARCHAR(100) NULL,

  -- Kontak
  ADD COLUMN home_phone VARCHAR(20) NULL,
  ADD COLUMN phone_2 VARCHAR(20) NULL,
  ADD COLUMN fax VARCHAR(20) NULL,

  -- Alamat Surat Menyurat
  ADD COLUMN mailing_same_as_ktp BOOLEAN DEFAULT TRUE,
  ADD COLUMN mailing_address VARCHAR(255) NULL,
  ADD COLUMN mailing_rt_rw VARCHAR(20) NULL,
  ADD COLUMN mailing_kelurahan VARCHAR(100) NULL,
  ADD COLUMN mailing_kecamatan VARCHAR(100) NULL,
  ADD COLUMN mailing_kota VARCHAR(100) NULL,

  -- Data Pekerjaan
  ADD COLUMN occupation VARCHAR(100) NULL,
  ADD COLUMN company_name VARCHAR(150) NULL,
  ADD COLUMN job_title VARCHAR(100) NULL,
  ADD COLUMN office_phone VARCHAR(20) NULL,
  ADD COLUMN office_address VARCHAR(255) NULL,
  ADD COLUMN office_rt_rw VARCHAR(20) NULL,
  ADD COLUMN office_kelurahan VARCHAR(100) NULL,
  ADD COLUMN office_kecamatan VARCHAR(100) NULL,
  ADD COLUMN office_kota VARCHAR(100) NULL,

  -- Emergency Contact
  ADD COLUMN emergency_name VARCHAR(150) NULL,
  ADD COLUMN emergency_relationship ENUM('orang_tua','anak','teman','suami','istri','saudara','lainnya') NULL,
  ADD COLUMN emergency_phone VARCHAR(20) NULL,
  ADD COLUMN emergency_email VARCHAR(150) NULL;
```

### Master `data_sources`

```sql
CREATE TABLE data_sources (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT NOT NULL,
  code VARCHAR(20) UNIQUE,    -- 'PAMERAN', 'ONLINE', 'REFERRAL', 'WALK_IN'
  name VARCHAR(100),
  is_active BOOLEAN DEFAULT TRUE,
  created_at, updated_at
);

-- Seed: Pameran, Online, Referral, Walk-In, Brochure, Social Media, dll
```

---

## 💰 Phase 3: Payment Foundation Enhancement (1-2 minggu)

**Dependencies:** Phase 2 done
**Output:** Payment enums + Memo model + PaymentAllocation enhancement + Free DP/Subsidi DP

### Tasks

| # | Task | Effort | Notes |
|---|------|--------|-------|
| 3.1 | `PaymentType` enum (BF/DP/ANG/ADM) + migration | 1h | Replace string status |
| 3.2 | `PaymentMethod` enum (TN/KD/KK/TR/BG/DN/KR) + migration | 1h | Foundation |
| 3.3 | `PaymentAllocation` source_type enhancement | 2h | Add: cash, memo, discount, kpr_disbursement |
| 3.4 | `Memo` model (Free DP / Subsidi DP / adjustment) | 4h | NUmerator pattern MEM/JR/26/V/00xxx |
| 3.5 | `GrantFreeDp` action class | 4h | Create memo + allocate to all DP schedules |
| 3.6 | `GrantSubsidiDp` action class | 4h | Partial DP coverage |
| 3.7 | `CancelFreeDp` / `CancelSubsidiDp` actions | 3h | Reverse allocation |
| 3.8 | Approval workflow integration | 4h | Free DP requires Finance Manager approval |
| 3.9 | Memo controller + UI | 4h | List + create + approval |
| 3.10 | Free DP / Subsidi DP buttons di Contract detail | 2h | 4 buttons + modal |
| 3.11 | Contract summary computed fields | 4h | Nilai Kontrak, Tagihan, Biaya, Pembayaran, Pelunasan% |
| 3.12 | Payment Schedule sub-row UI (parent + children) | 4h | Tree-like display |
| 3.13 | Schedule numbering `.1, .2, .3` suffix | 2h | Computed attr |
| 3.14 | Payment method per allocation | 2h | UI dropdown |
| 3.15 | Test coverage Memo + FreeDp flow | 4h | Pest tests |

### New Tables

```sql
CREATE TABLE memos (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT,
  memo_number VARCHAR(50) UNIQUE,       -- MEM/JR/26/V/00200
  type ENUM('free_dp','subsidi_dp','adjustment','other'),
  reference_type VARCHAR(50),           -- polymorphic: 'contract'
  reference_id BIGINT,
  amount DECIMAL(18,2),
  reason TEXT,
  status ENUM('draft','pending_approval','approved','cancelled'),
  approved_by BIGINT REFERENCES users(id),
  approved_at TIMESTAMP,
  cancelled_by BIGINT,
  cancelled_at TIMESTAMP,
  created_by BIGINT REFERENCES users(id),
  created_at, updated_at
);

ALTER TABLE payment_allocations
  ADD COLUMN source_type ENUM('cash','memo','discount','kpr_disbursement','manual') DEFAULT 'cash',
  ADD COLUMN source_id BIGINT NULL,
  ADD COLUMN reference_number VARCHAR(100) NULL;
```

---

## 📑 Phase 4: Contract Lifecycle (PPJB + Documents + Conditional Tabs) (1 minggu)

**Dependencies:** Phase 3 done
**Output:** PPJB 4-state machine + dual numbering + document checklist + conditional tabs UI

### Tasks

| # | Task | Effort |
|---|------|--------|
| 4.1 | `PpjbStatus` enum (4-state: belum/print/tanda_tangan/selesai) | 1h |
| 4.2 | PPJB dual numbering (system + manual) | 2h |
| 4.3 | Tanggal triplet (ppjb, cetak, tanda_tangan) di migration | 1h |
| 4.4 | `ContractDocument` table (KK, KTP, NPWP checklist) | 4h |
| 4.5 | Document compliance gate (PPJB tidak bisa sign tanpa lengkap) | 2h |
| 4.6 | UI tab Proses PPJB (left: status, right: document checklist) | 4h |
| 4.7 | Contract Edit tab navigation (Edit/Jurnal/Tagihan/Komisi/PPJB/Gimmick) | 4h |
| 4.8 | Conditional "Proses KPR" tab (visible only when cara_bayar=KPR) | 2h |
| 4.9 | Prev/Next navigation antar kontrak | 1h |
| 4.10 | OK vs Apply button pattern (Save&Close vs Save) | 1h |
| 4.11 | Test coverage PPJB state machine | 3h |

### New Tables

```sql
CREATE TABLE contract_documents (
  id BIGINT PRIMARY KEY,
  contract_id BIGINT REFERENCES contracts(id),
  document_type ENUM('kk','ktp','npwp','akta_nikah','surat_kuasa','lainnya'),
  is_present BOOLEAN DEFAULT FALSE,
  file_path VARCHAR(255) NULL,
  uploaded_at TIMESTAMP NULL,
  uploaded_by BIGINT NULL,
  notes TEXT NULL,
  created_at, updated_at,
  UNIQUE(contract_id, document_type)
);

ALTER TABLE contracts
  ADD COLUMN ppjb_status ENUM('belum_diproses','print','proses_tanda_tangan','selesai') DEFAULT 'belum_diproses',
  ADD COLUMN ppjb_system_number VARCHAR(50) NULL,
  ADD COLUMN ppjb_manual_number VARCHAR(50) NULL,
  ADD COLUMN ppjb_use_manual_number BOOLEAN DEFAULT FALSE,
  ADD COLUMN ppjb_date DATE NULL,
  ADD COLUMN ppjb_printed_at DATE NULL,
  ADD COLUMN ppjb_signed_at DATE NULL;
```

---

## 🎁 Phase 5: Gimmick Module — Marketing Promo (1 minggu)

**Dependencies:** Phase 4 done
**Output:** Full Gimmick lifecycle (Master Package → Apply ke Kontrak → Track Diterima)

### Tasks

| # | Task | Effort |
|---|------|--------|
| 5.1 | `GimmickType` enum (8 types: voucher/physical/service/dll) | 1h |
| 5.2 | `GimmickItem` master CRUD (Kode/Tipe/Nama/Satuan/Harga) | 4h |
| 5.3 | `GimmickPackage` master (per cluster) + CRUD | 4h |
| 5.4 | `gimmick_package_items` pivot (items per package + qty default) | 2h |
| 5.5 | `ContractGimmick` model (link kontrak ↔ items + status diberikan/diterima) | 4h |
| 5.6 | UI Tab Gimmick di Contract edit (table 12 columns) | 6h |
| 5.7 | Apply Gimmick Package action (saat kontrak created) | 3h |
| 5.8 | Toggle Diberikan/Diterima + tanggal | 2h |
| 5.9 | Marketing cost analytics (total gimmick value per kontrak/cluster) | 4h |
| 5.10 | Test coverage | 3h |

### New Tables

```sql
CREATE TABLE gimmick_items (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT,
  kode_barang VARCHAR(20),
  tipe_barang ENUM('cash','discount','service_waiver','physical','voucher_3rd','service','warranty','voucher'),
  nama_barang VARCHAR(150),
  satuan_barang VARCHAR(50),       -- 'VOUCHER', 'BUAH', 'M2'
  harga_satuan DECIMAL(18,2),
  is_active BOOLEAN DEFAULT TRUE,
  created_at, updated_at
);

CREATE TABLE gimmick_packages (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT,
  project_id BIGINT,
  cluster_code VARCHAR(50),        -- 'CLUSTER 3.4'
  name VARCHAR(150),
  is_active BOOLEAN DEFAULT TRUE,
  valid_from DATE, valid_until DATE,
  created_at, updated_at
);

CREATE TABLE gimmick_package_items (
  package_id BIGINT,
  gimmick_item_id BIGINT,
  qty_default INT DEFAULT 1,
  target_lunas_days INT NULL,      -- target lunas dalam hari setelah kontrak
  PRIMARY KEY (package_id, gimmick_item_id)
);

CREATE TABLE contract_gimmicks (
  id BIGINT PRIMARY KEY,
  contract_id BIGINT,
  gimmick_item_id BIGINT,
  qty INT DEFAULT 1,
  harga_satuan DECIMAL(18,2),       -- snapshot harga saat kontrak
  total_harga DECIMAL(18,2),
  target_lunas DATE NULL,
  diberikan BOOLEAN DEFAULT TRUE,   -- janji di kontrak
  diterima BOOLEAN DEFAULT FALSE,   -- customer sudah terima
  tgl_diterima DATE NULL,
  notes TEXT NULL,
  created_at, updated_at
);
```

---

## 🧮 Phase 6: Calculators + UX Quick Wins (1 minggu)

**Dependencies:** Phase 3 (untuk Skema calculator butuh PaymentScheme)
**Output:** 3 kalkulator + 10 quick UX wins

### Calculators

| # | Task | Effort |
|---|------|--------|
| 6.1 | **BPHTB Calculator** + UI | 1 hari |
| 6.2 | **KPR Calculator** + UI | 2 jam |
| 6.3 | **Master Payment Schemes** table + CRUD UI | 1 hari |
| 6.4 | **Skema Calculator** (depends 6.3) | 1 hari |

### Quick UX Wins

| # | Task | Effort |
|---|------|--------|
| 6.5 | Per-record Audit Log Viewer button | 2h |
| 6.6 | Prev/Next navigation booking detail | 30m |
| 6.7 | Display Input/Edit timestamp di header | 15m |
| 6.8 | OK vs Apply pattern global | 30m |
| 6.9 | DateTime field Batas Waktu (vs Date only) | 30m |
| 6.10 | Rename "DP Amount" → "Nilai Pengikatan" | 10m |
| 6.11 | Customer Daftar context-aware (`?context=booking`) | 2h |
| 6.12 | Site Plan: Add "Buat Booking" button | 2h |
| 6.13 | Reminder dashboard (per-user task & due date) | 1 hari |
| 6.14 | Welcome message + last login info di dashboard | 30m |
| 6.15 | "PT. Mitra Usaha Propertindo" branding di login | 5m |

### BPHTB Calculator (formula confirmed):

```php
Total NJOP = (Luas Tanah × NJOP/m²) + (Luas Bangunan × NJOP/m²)
NPOP       = MAX(Nilai AJB, Total NJOP)
NPOPKP     = NPOP − NPOTKP
BPHTB      = NPOPKP × 5%
```

### Master `payment_schemes`

```sql
CREATE TABLE payment_schemes (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT,
  project_id BIGINT,
  cluster_code VARCHAR(50) NULL,
  code VARCHAR(20),               -- '032'
  name VARCHAR(150),               -- 'KPR FREE DP CLUSTER 3.4'
  type ENUM('kpr','cash','cicilan','custom'),
  dp_pct DECIMAL(5,2),            -- default 30%
  tenor_months INT,
  interest_rate DECIMAL(5,2),
  ppn_pct DECIMAL(5,2) DEFAULT 11,
  default_discount_pct DECIMAL(5,2) DEFAULT 0,
  is_active BOOLEAN DEFAULT TRUE,
  template_schedule_json JSONB,    -- pre-defined schedule template
  created_at, updated_at
);
```

---

## 🚚 Phase 7: Migration Tools + Cutover Prep (1-2 minggu)

**Dependencies:** Phase 2-6 done
**Output:** Bulk import dari Java Core System Lama + custom error pages + go-live readiness

### Tasks

| # | Task | Effort |
|---|------|--------|
| 7.1 | UI Bulk Upload Customer (Excel) | 4h |
| 7.2 | UI Bulk Upload Booking | 4h |
| 7.3 | UI Bulk Upload Kontrak | 4h |
| 7.4 | UI Bulk Upload Jadwal Tagihan | 4h |
| 7.5 | UI Bulk Upload Pembayaran (Payment History) | 4h |
| 7.6 | Migration validator (dry-run + report) | 4h |
| 7.7 | Custom Error Page: `/session-expired` | 2h |
| 7.8 | Custom Error Page: 404 / 403 Indonesian | 2h |
| 7.9 | Numerator pattern multi-format support | 4h |
| 7.10 | Status code mapping (Java Core System Lama A/E → IPRO BookingStatus) | 2h |
| 7.11 | Cluster code di Unit + multi-cluster Project | 4h |
| 7.12 | Roman numeral month di Numerator | 2h |
| 7.13 | Activity Log polymorphic model + UI | 1-2 hari |
| 7.14 | Cutover plan + UAT scenarios | 1 hari |
| 7.15 | Production deployment + monitoring | 1 hari |

### CRM Activity Log Schema

```sql
CREATE TABLE activity_logs (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT,
  subject_type VARCHAR(50),         -- polymorphic: Booking, Contract, Customer
  subject_id BIGINT,
  category ENUM('follow_up_document','verbal_agreement','complain',
                'legal_matter','phone_activity','customer_meeting',
                'latest_info','other'),
  description TEXT,
  logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  logged_by BIGINT REFERENCES users(id),
  attachment_path VARCHAR(255) NULL,
  attachment_name VARCHAR(255) NULL,
  attachment_mime VARCHAR(100) NULL,
  attachment_size BIGINT NULL,
  created_at, updated_at,
  INDEX(subject_type, subject_id)
);
```

---

## 📈 Risk & Mitigation

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Data migration tidak akurat | Medium | High | Dry-run + validator + sample reconciliation 100 record |
| Tim sales bingung istilah baru | High | Medium | Glossary doc + training session + alias istilah (Reservasi/Booking) |
| Gimmick types tidak match Java Core System Lama | Medium | Medium | Konfirm dengan tim MUP early di Phase 5 |
| PPJB legal change | Low | High | Review dengan legal officer di Phase 4 |
| Free DP/Subsidi tidak match alur sebenarnya | Medium | High | Wireframe + approval di akhir Phase 3 |
| Performance saat 10K+ unit | Low | Medium | Index optimization + caching di Phase 7 |

---

## 🎯 Definition of Done per Phase

### Phase 2 (Customer Expansion)
- [ ] Customer form 7 sections render correctly
- [ ] All 30 new fields nullable, no breaking change
- [ ] Existing tests pass
- [ ] Sample customer create via UI works
- [ ] Data Sources master seedable
- [ ] Address parser tested

### Phase 3 (Payment Foundation)
- [ ] PaymentType + PaymentMethod enums used everywhere
- [ ] PaymentAllocation supports 5 source types
- [ ] Memo create + approve + use as allocation works
- [ ] Free DP grant + cancel cycle work
- [ ] Subsidi DP partial allocation works
- [ ] Contract summary computed correctly
- [ ] Test coverage > 80% for new code

### Phase 4 (Contract Lifecycle)
- [ ] PPJB transition belum→print→tanda_tangan→selesai works
- [ ] Cannot sign PPJB if KK/KTP/NPWP not complete
- [ ] Manual + System number selectable
- [ ] All tab navigation works
- [ ] Conditional Proses KPR tab shows correctly

### Phase 5 (Gimmick)
- [ ] Gimmick package CRUD works
- [ ] Apply package to kontrak auto-creates contract_gimmicks
- [ ] Toggle diberikan/diterima persists
- [ ] Total marketing cost per kontrak displayable
- [ ] Per-cluster gimmick filter works

### Phase 6 (Calculators + UX)
- [ ] BPHTB calc accurate per known sample
- [ ] KPR amortization matches Excel
- [ ] Skema calc matches selected scheme
- [ ] All 10 quick wins verified by user

### Phase 7 (Migration + Cutover)
- [ ] Excel import 1000-row sample success
- [ ] Custom error pages render correctly
- [ ] Activity log searchable per kontrak
- [ ] UAT pass dengan tim MUP
- [ ] Production deploy + smoke test green
- [ ] Rollback plan documented

---

## 👥 Team Roles & RACI

| Role | Responsibility |
|------|----------------|
| **Tech Lead (Claude + JV)** | Architecture decisions, code review, integration testing |
| **PM (MUP IT)** | Requirement clarification, priority confirmation, UAT coordination |
| **Sales Team Lead** | Validate workflow parity, gimmick package definition |
| **Finance Manager** | Validate Free DP approval flow, Memo numbering rules |
| **Legal Officer** | Validate PPJB lifecycle + document checklist |
| **Marketing Officer** | Define Data Sources + Gimmick types catalog |

---

## 📚 Reference Documents

- `GAP_ANALYSIS_OLD_vs_NEW.md` — full live observation Java Core System Lama
- `REBUILD_BLUEPRINT_LARAVEL13.md` — original architecture spec
- `LIVE_DEMO_LOG.md` — Phase 1 verification log
- `OPERATIONAL_GUIDE.md` — current operational procedures
- `MODULES_COMPLETE.md` — Phase 1 module coverage

---

## 🚀 Next Actions

1. **Review roadmap** dengan tim MUP — konfirmasi prioritas Phase 2-7
2. **Sprint planning Phase 2** — assign tasks, set 2-week sprint
3. **Stakeholder workshops**:
   - Marketing: define Data Sources + Gimmick catalog
   - Legal: review PPJB doc compliance
   - Finance: review Memo/Free DP approval matrix
4. **Sample data dari Java Core System Lama** — export 100 customer + 50 kontrak untuk migration test
5. **Setup CI/CD** for automated test + deploy preview environment

---

🤖 Roadmap ini draft awal berdasarkan gap analysis live observation. Setiap phase punya buffer 20% untuk unforeseen issues. Sprint review setiap akhir phase untuk adjustment scope.
