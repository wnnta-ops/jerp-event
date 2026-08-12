# ERD — J-ERP (eProperty Clone) Schema (Laravel 12 + MySQL 8)

**Entity Relationship Document — schema target untuk meng-clone ke MySQL.**
Status: spesifikasi target (forward-looking). Sumber: [ERD.md](ERD.md) (existing schema, multi-DB SQL Server).
Companion: [PRD_cloning.md](PRD_cloning.md), [claude_cloning.md](claude_cloning.md).

---

## 1. Prinsip Desain

### 1.1 Single database, prefix per modul

```
Database: jerp (MySQL 8 / MariaDB 10.11+)
Charset:  utf8mb4
Collation: utf8mb4_unicode_ci
Engine:   InnoDB

Prefix konvensi:
  (no prefix)  shared globals  : users, roles, permissions, projects, companies, banks,
                                  numerators, settings, activity_log, jobs, ...
  fo_           Front Office   : fo_customers, fo_units, fo_kontrak, fo_tts, fo_bkm, ...
  bo_           Back Office    : bo_accounts, bo_jurnal_header, bo_invoices_ap, ...
  bankloan_     KPR/BankLoan   : bankloan_contracts, bankloan_contract_banks, ...
  backup_       Backup (TuxArch): backup_schedules, backup_logs, backup_configs
```

### 1.2 Konvensi kolom universal

Setiap tabel **transaksi** wajib punya:

```sql
id              BIGINT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
tenant_id       BIGINT UNSIGNED  NOT NULL DEFAULT 1,         -- multi-tenant ready
company_id      BIGINT UNSIGNED  NULL,                       -- per-company isolation
project_id      BIGINT UNSIGNED  NULL,                       -- per-project isolation
created_at      TIMESTAMP        NULL,
updated_at      TIMESTAMP        NULL,
deleted_at      TIMESTAMP        NULL,                       -- soft delete
created_by      BIGINT UNSIGNED  NULL,                       -- FK users.id
updated_by      BIGINT UNSIGNED  NULL,                       -- FK users.id
deleted_by      BIGINT UNSIGNED  NULL,                       -- FK users.id

INDEX idx_tenant (tenant_id),
INDEX idx_company (company_id),
INDEX idx_project (project_id),
INDEX idx_created_at (created_at)
```

Tabel **master** (kategori, lokasi, dst) cukup `id`, `tenant_id`, `created_at`, `updated_at`, `deleted_at`.
Tabel **junction / pivot** tidak perlu audit columns (cukup `created_at`).

### 1.3 Foreign keys

- Pakai `BIGINT UNSIGNED` untuk semua FK
- Constraint: `FOREIGN KEY (xxx_id) REFERENCES xxx(id) ON DELETE RESTRICT ON UPDATE CASCADE`
- Untuk soft-delete entities, FK tetap `RESTRICT` — soft delete tidak menghapus dari DB
- Cross-module FK diperbolehkan (manfaat single-DB)

### 1.4 Naming

- Tabel: `snake_case`, plural (Laravel default): `fo_customers`, `bo_jurnal_details`
- Kolom: `snake_case`, singular: `customer_id`, `created_at`, `kontrak_number`
- Pertahankan istilah Indonesia di nama kolom jika domain (no_kontrak, status_kpr, jenis_pembayaran) — operator + dokumen sudah pakai
- Boolean: prefix `is_`, `has_`: `is_active`, `is_posted`, `has_pajak`
- Enum: pakai MySQL `ENUM()` untuk yang terbatas & jarang berubah; pakai tabel master untuk yang sering update

### 1.5 Money & dates

- Currency: `DECIMAL(18, 2)` untuk Rupiah (max ~1 quadrillion, aman untuk properti) atau `DECIMAL(20, 4)` untuk presisi tinggi (KPR perhitungan bunga)
- Date: `DATE` untuk tanggal kalender, `DATETIME` untuk timestamp business, `TIMESTAMP` untuk audit (auto-UTC)
- Aplikasi: simpan UTC, tampilkan `Asia/Jakarta` (config `app.timezone`)

### 1.6 JSON & dynamic fields

- Setting per-tenant / per-modul: pakai `JSON` column (`settings_json`)
- Audit `properties` (Spatie ActivityLog): `JSON`
- Hindari JSON untuk data transaksi inti (susah di-index, di-query)

---

## 2. Database Diagram (High-Level)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                            SHARED                                        │
│  users  roles  permissions  role_user  permission_role                   │
│  tenants  companies  projects  banks  numerators  settings               │
│  activity_log  jobs  failed_jobs  cache                                  │
└─────────────────────────────────────────────────────────────────────────┘
                                  │
        ┌─────────────────────────┼─────────────────────────┐
        ▼                         ▼                         ▼
┌────────────────┐      ┌────────────────────┐    ┌────────────────────┐
│      FO        │      │       BO           │    │     BankLoan       │
│ ─────────────  │      │ ─────────────────  │    │ ────────────────── │
│ fo_customers   │◄────►│ bo_accounts        │    │ bankloan_customers │
│ fo_units       │      │ bo_jurnal_header   │◄───┤ bankloan_contracts │
│ fo_reservasi   │      │ bo_jurnal_detail   │    │ bankloan_contract_ │
│ fo_kontrak     │──────│ bo_invoice_ap      │    │   banks            │
│ fo_skema       │      │ bo_kuitansi        │    │ bankloan_bi_       │
│ fo_tagihan     │      │ bo_bkm  bo_bkk     │    │   checkings        │
│ fo_tts         │──────│ bo_bgm  bo_bgk     │    │ bankloan_ra        │
│ fo_bkm         │      │ bo_master_stok     │    │ bankloan_pr        │
│ fo_bg          │      │ bo_fpb  bo_pr      │    │ bankloan_rr        │
│ fo_va          │      │ bo_karyawan        │    │ bankloan_mom       │
│ fo_memo        │      │ bo_brq             │    │ bankloan_documents │
│ fo_fp          │      └────────────────────┘    └────────────────────┘
│ fo_collection_                                            │
│   followup     │                                          │
│ fo_legal_*     │                                          │
│ fo_launching_  │              ┌────────────────────┐      │
│   nup          │              │      Backup        │      │
│ fo_approval    │              │ ─────────────────  │      │
│ fo_komisi_*    │              │ backup_schedules   │      │
│ fo_agent       │              │ backup_logs        │      │
│ fo_gimmick     │              │ backup_configs     │      │
└────────────────┘              └────────────────────┘      │
                                                             │
        ▲                                                    │
        │  event-based sync (KontrakCreated → SyncBankLoan)  │
        └────────────────────────────────────────────────────┘
```

---

## 3. Shared Tables (no prefix)

### 3.1 Multi-tenancy & RBAC

```sql
CREATE TABLE tenants (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20)   NOT NULL UNIQUE,          -- e.g. 'MUP'
    name            VARCHAR(150)  NOT NULL,                  -- e.g. 'Java Residence'
    is_active       BOOLEAN       NOT NULL DEFAULT TRUE,
    settings_json   JSON          NULL,
    created_at      TIMESTAMP     NULL,
    updated_at      TIMESTAMP     NULL
);

CREATE TABLE users (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         BIGINT UNSIGNED NOT NULL DEFAULT 1,
    username          VARCHAR(50)  NOT NULL,
    email             VARCHAR(150) NOT NULL,
    email_verified_at TIMESTAMP    NULL,
    password          VARCHAR(255) NOT NULL,                 -- Bcrypt
    full_name         VARCHAR(150) NOT NULL,
    phone             VARCHAR(30)  NULL,
    avatar_path       VARCHAR(255) NULL,
    is_active         BOOLEAN      NOT NULL DEFAULT TRUE,
    is_blocked        BOOLEAN      NOT NULL DEFAULT FALSE,
    last_login_at     TIMESTAMP    NULL,
    last_login_ip     VARCHAR(45)  NULL,
    failed_attempts   TINYINT      NOT NULL DEFAULT 0,
    locked_until      TIMESTAMP    NULL,
    password_changed_at TIMESTAMP  NULL,
    two_factor_secret TEXT         NULL,
    two_factor_recovery_codes TEXT NULL,
    remember_token    VARCHAR(100) NULL,
    settings_json     JSON         NULL,                      -- per-user prefs
    created_at        TIMESTAMP    NULL,
    updated_at        TIMESTAMP    NULL,
    deleted_at        TIMESTAMP    NULL,
    UNIQUE KEY uq_tenant_username (tenant_id, username),
    UNIQUE KEY uq_tenant_email (tenant_id, email),
    INDEX idx_locked_until (locked_until)
);

-- Spatie/laravel-permission tables (auto-generated):
-- permissions, roles, model_has_permissions, model_has_roles, role_has_permissions
-- TAMBAHKAN tenant_id ke roles & permissions untuk per-tenant scope

CREATE TABLE user_login_history (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    login_at     TIMESTAMP    NOT NULL,
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(500) NULL,
    is_success   BOOLEAN      NOT NULL,
    failure_reason VARCHAR(100) NULL,
    INDEX idx_user_login (user_id, login_at DESC),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE user_password_history (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at   TIMESTAMP    NOT NULL,
    INDEX idx_user (user_id, created_at DESC),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE user_verification_codes (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    code         VARCHAR(10)  NOT NULL,
    purpose      ENUM('email_confirm','forgot_password','two_factor','phone_verify') NOT NULL,
    expires_at   TIMESTAMP    NOT NULL,
    consumed_at  TIMESTAMP    NULL,
    created_at   TIMESTAMP    NULL,
    INDEX idx_user_purpose (user_id, purpose),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 3.2 Master shared

```sql
CREATE TABLE companies (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     BIGINT UNSIGNED NOT NULL DEFAULT 1,
    code          VARCHAR(20)  NOT NULL,
    name          VARCHAR(150) NOT NULL,
    legal_name    VARCHAR(200) NULL,
    address       TEXT         NULL,
    city          VARCHAR(100) NULL,
    npwp          VARCHAR(30)  NULL,
    logo_path     VARCHAR(255) NULL,
    is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
    settings_json JSON         NULL,
    created_at    TIMESTAMP    NULL,
    updated_at    TIMESTAMP    NULL,
    deleted_at    TIMESTAMP    NULL,
    UNIQUE KEY uq_tenant_code (tenant_id, code)
);

CREATE TABLE projects (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     BIGINT UNSIGNED NOT NULL DEFAULT 1,
    company_id    BIGINT UNSIGNED NOT NULL,
    code          VARCHAR(20)  NOT NULL,
    name          VARCHAR(150) NOT NULL,
    address       TEXT         NULL,
    city          VARCHAR(100) NULL,
    cover_image_path VARCHAR(255) NULL,
    siteplan_image_path VARCHAR(255) NULL,
    is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
    launching_date DATE        NULL,
    settings_json JSON         NULL,
    created_at    TIMESTAMP    NULL,
    updated_at    TIMESTAMP    NULL,
    deleted_at    TIMESTAMP    NULL,
    UNIQUE KEY uq_tenant_code (tenant_id, code),
    INDEX idx_company (company_id),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);

CREATE TABLE banks (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     BIGINT UNSIGNED NOT NULL DEFAULT 1,
    code          VARCHAR(10)  NOT NULL,                     -- 'BCA', 'BNI', 'MANDIRI'
    name          VARCHAR(100) NOT NULL,
    short_name    VARCHAR(20)  NULL,
    address       TEXT         NULL,
    city          VARCHAR(100) NULL,
    branch        VARCHAR(150) NULL,
    account_number VARCHAR(50) NULL,
    account_name  VARCHAR(150) NULL,
    swift_code    VARCHAR(20)  NULL,
    is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
    settings_json JSON         NULL,
    created_at    TIMESTAMP    NULL,
    updated_at    TIMESTAMP    NULL,
    deleted_at    TIMESTAMP    NULL,
    UNIQUE KEY uq_tenant_code (tenant_id, code)
);

CREATE TABLE cost_centers (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     BIGINT UNSIGNED NOT NULL DEFAULT 1,
    company_id    BIGINT UNSIGNED NULL,
    code          VARCHAR(20)  NOT NULL,
    name          VARCHAR(150) NOT NULL,
    parent_id     BIGINT UNSIGNED NULL,                       -- hierarki
    is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMP    NULL,
    updated_at    TIMESTAMP    NULL,
    deleted_at    TIMESTAMP    NULL,
    UNIQUE KEY uq_tenant_code (tenant_id, code),
    FOREIGN KEY (parent_id) REFERENCES cost_centers(id)
);

CREATE TABLE numerators (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     BIGINT UNSIGNED NOT NULL DEFAULT 1,
    company_id    BIGINT UNSIGNED NULL,                       -- nullable = global
    code          VARCHAR(50)  NOT NULL,                      -- 'KPR', 'TTS', 'BKM', ...
    name          VARCHAR(150) NOT NULL,
    prefix        VARCHAR(20)  NOT NULL,
    format        VARCHAR(100) NOT NULL,                      -- '{PREFIX}-{YEAR}-{####}'
    reset_period  ENUM('never','yearly','monthly','daily') NOT NULL DEFAULT 'yearly',
    padding       TINYINT      NOT NULL DEFAULT 5,
    current_value BIGINT       NOT NULL DEFAULT 0,
    last_reset_at TIMESTAMP    NULL,
    is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMP    NULL,
    updated_at    TIMESTAMP    NULL,
    UNIQUE KEY uq_tenant_company_code (tenant_id, company_id, code)
);

CREATE TABLE settings (
    -- spatie/laravel-settings table — auto-generated
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group`       VARCHAR(100) NOT NULL,
    name          VARCHAR(100) NOT NULL,
    locked        BOOLEAN      NOT NULL DEFAULT FALSE,
    payload       JSON         NOT NULL,
    created_at    TIMESTAMP    NULL,
    updated_at    TIMESTAMP    NULL,
    UNIQUE KEY uq_group_name (`group`, name)
);

CREATE TABLE activity_log (
    -- spatie/laravel-activitylog table — auto-generated
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    log_name        VARCHAR(255) NULL,
    description     TEXT         NOT NULL,
    subject_type    VARCHAR(255) NULL,
    subject_id      BIGINT       UNSIGNED NULL,
    causer_type     VARCHAR(255) NULL,
    causer_id       BIGINT       UNSIGNED NULL,
    properties      JSON         NULL,
    batch_uuid      CHAR(36)     NULL,
    event           VARCHAR(255) NULL,
    created_at      TIMESTAMP    NULL,
    updated_at      TIMESTAMP    NULL,
    INDEX subject (subject_type, subject_id),
    INDEX causer (causer_type, causer_id),
    INDEX log_name (log_name)
);

-- standard Laravel: jobs, failed_jobs, cache, sessions, password_reset_tokens, personal_access_tokens
```

---

## 4. FO — Front Office Tables

### 4.1 Customer & related

```sql
CREATE TABLE fo_customers (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       BIGINT UNSIGNED NOT NULL DEFAULT 1,
    code            VARCHAR(30)  NOT NULL,                    -- "Nomor Customer", #AUTO#
    project_id      BIGINT UNSIGNED NULL,
    sumber_data_id  BIGINT UNSIGNED NULL,                     -- FK ke fo_sumber_data (iklan)
    full_name       VARCHAR(200) NOT NULL,                    -- Nama Lengkap (KTP)
    nik             VARCHAR(30)  NOT NULL,                    -- Nomor KTP
    npwp            VARCHAR(30)  NULL,
    birth_place     VARCHAR(100) NULL,
    birth_date      DATE         NULL,
    gender          ENUM('L','P') NULL,
    religion        VARCHAR(30)  NULL,
    marital_status  VARCHAR(30)  NULL,
    occupation      VARCHAR(100) NULL,
    income_range    VARCHAR(50)  NULL,
    email           VARCHAR(150) NULL,
    phone           VARCHAR(30)  NULL,
    phone_alt       VARCHAR(30)  NULL,
    address         TEXT         NULL,
    address_ktp     TEXT         NULL,
    city            VARCHAR(100) NULL,
    province        VARCHAR(100) NULL,
    postal_code     VARCHAR(20)  NULL,
    photo_path      VARCHAR(255) NULL,
    ktp_image_path  VARCHAR(255) NULL,
    status          ENUM('LEAD','ACTIVE','BLOCKED','MERGED','DELETED') NOT NULL DEFAULT 'LEAD',
    merged_into_id  BIGINT UNSIGNED NULL,                     -- self-ref untuk merge customer
    notes           TEXT         NULL,
    -- audit
    created_at      TIMESTAMP    NULL,
    updated_at      TIMESTAMP    NULL,
    deleted_at      TIMESTAMP    NULL,
    created_by      BIGINT UNSIGNED NULL,
    updated_by      BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_tenant_code (tenant_id, code),
    INDEX idx_nik (nik),
    INDEX idx_phone (phone),
    INDEX idx_email (email),
    INDEX idx_status (status),
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (merged_into_id) REFERENCES fo_customers(id),
    FULLTEXT KEY ft_search (full_name, email, phone, nik)
);

CREATE TABLE fo_customer_histories (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id     BIGINT UNSIGNED NOT NULL,
    event           VARCHAR(50)  NOT NULL,                    -- e.g. 'CREATED','UPDATED','MERGED','BLOCKED'
    description     TEXT         NULL,
    user_id         BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP    NULL,
    INDEX idx_customer (customer_id, created_at DESC),
    FOREIGN KEY (customer_id) REFERENCES fo_customers(id) ON DELETE CASCADE
);

CREATE TABLE fo_sumber_data (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    code VARCHAR(30) NOT NULL,                                -- 'SOSMED','FLYER','REFF','LAINNYA'
    name VARCHAR(100) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_code (tenant_id, code)
);
```

### 4.2 Master Unit, Skema, Price List

```sql
CREATE TABLE fo_unit_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    project_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    luas_bangunan DECIMAL(10,2) NULL,
    luas_tanah    DECIMAL(10,2) NULL,
    kamar_tidur   TINYINT NULL,
    kamar_mandi   TINYINT NULL,
    specs_json    JSON NULL,                                  -- detail spek lain
    image_path    VARCHAR(255) NULL,
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_project_code (project_id, code),
    FOREIGN KEY (project_id) REFERENCES projects(id)
);

CREATE TABLE fo_units (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       BIGINT UNSIGNED NOT NULL DEFAULT 1,
    project_id      BIGINT UNSIGNED NOT NULL,
    unit_type_id    BIGINT UNSIGNED NULL,
    code            VARCHAR(50)  NOT NULL,                    -- KodeUnit, mis "A-12-05"
    cluster         VARCHAR(50)  NULL,
    block           VARCHAR(20)  NULL,
    no_unit         VARCHAR(20)  NULL,
    floor           VARCHAR(20)  NULL,
    address         VARCHAR(255) NULL,
    luas_bangunan   DECIMAL(10,2) NULL,
    luas_tanah      DECIMAL(10,2) NULL,
    harga_jual      DECIMAL(18,2) NOT NULL DEFAULT 0,
    harga_dasar     DECIMAL(18,2) NULL,
    status          ENUM('AVAILABLE','HOLD','BOOKING','NUP','SOLD','CANCELLED') NOT NULL DEFAULT 'AVAILABLE',
    color_hex       VARCHAR(7)   NULL,                        -- untuk peta site plan
    coord_x         DECIMAL(10,4) NULL,                       -- koordinat di siteplan
    coord_y         DECIMAL(10,4) NULL,
    coord_polygon   JSON         NULL,                        -- polygon SVG
    spec_json       JSON         NULL,
    photo_path      VARCHAR(255) NULL,
    notes           TEXT         NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_project_code (project_id, code),
    INDEX idx_status (status),
    INDEX idx_cluster_block (cluster, block),
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (unit_type_id) REFERENCES fo_unit_types(id)
);

CREATE TABLE fo_unit_status_history (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id       BIGINT UNSIGNED NOT NULL,
    status_from   VARCHAR(20)  NULL,
    status_to     VARCHAR(20)  NOT NULL,
    reference_type VARCHAR(50) NULL,                          -- 'reservasi','kontrak','nup'
    reference_id  BIGINT UNSIGNED NULL,
    user_id       BIGINT UNSIGNED NULL,
    notes         TEXT         NULL,
    created_at    TIMESTAMP    NOT NULL,
    INDEX idx_unit (unit_id, created_at DESC),
    FOREIGN KEY (unit_id) REFERENCES fo_units(id) ON DELETE CASCADE
);

CREATE TABLE fo_price_lists (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    project_id   BIGINT UNSIGNED NOT NULL,
    code         VARCHAR(50)  NOT NULL,
    name         VARCHAR(150) NOT NULL,
    effective_from DATE       NOT NULL,
    effective_to   DATE       NULL,
    is_active    BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_project_code (project_id, code),
    FOREIGN KEY (project_id) REFERENCES projects(id)
);

CREATE TABLE fo_price_list_units (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    price_list_id BIGINT UNSIGNED NOT NULL,
    unit_id       BIGINT UNSIGNED NOT NULL,
    harga_jual    DECIMAL(18,2) NOT NULL,
    diskon        DECIMAL(18,2) NULL,
    notes         TEXT NULL,
    UNIQUE KEY uq_pl_unit (price_list_id, unit_id),
    FOREIGN KEY (price_list_id) REFERENCES fo_price_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES fo_units(id)
);

CREATE TABLE fo_skema (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    project_id   BIGINT UNSIGNED NULL,
    code         VARCHAR(30)  NOT NULL,
    name         VARCHAR(150) NOT NULL,
    tipe         ENUM('CASH','HARDCASH','CICILAN','KPR','KPA') NOT NULL,
    jumlah_termin SMALLINT NULL,
    bunga_tahunan DECIMAL(7,4) NULL,
    persen_dp    DECIMAL(5,2) NULL,
    description  TEXT NULL,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_code (tenant_id, code),
    FOREIGN KEY (project_id) REFERENCES projects(id)
);

CREATE TABLE fo_skema_termin (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skema_id    BIGINT UNSIGNED NOT NULL,
    urutan      SMALLINT NOT NULL,
    jenis       VARCHAR(50) NOT NULL,                         -- 'DP','BOOKING','CICILAN','PELUNASAN'
    bulan_ke    SMALLINT NULL,                                -- offset bulan dari tanggal kontrak
    persentase  DECIMAL(7,4) NULL,                            -- jika basis %
    nominal     DECIMAL(18,2) NULL,                           -- jika basis nominal tetap
    keterangan  VARCHAR(255) NULL,
    UNIQUE KEY uq_skema_urutan (skema_id, urutan),
    FOREIGN KEY (skema_id) REFERENCES fo_skema(id) ON DELETE CASCADE
);

CREATE TABLE fo_skema_diskon (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skema_id BIGINT UNSIGNED NOT NULL,
    nama VARCHAR(150) NOT NULL,
    persentase DECIMAL(7,4) NULL,
    nominal DECIMAL(18,2) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (skema_id) REFERENCES fo_skema(id) ON DELETE CASCADE
);

CREATE TABLE fo_skema_bunga (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skema_id BIGINT UNSIGNED NOT NULL,
    tahun_ke SMALLINT NOT NULL,
    bunga_persen DECIMAL(7,4) NOT NULL,
    FOREIGN KEY (skema_id) REFERENCES fo_skema(id) ON DELETE CASCADE
);

CREATE TABLE fo_gimmick (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    project_id BIGINT UNSIGNED NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    tipe VARCHAR(50) NULL,                                    -- 'CASHBACK','BARANG','VOUCHER'
    nominal DECIMAL(18,2) NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL
);
```

### 4.3 Agent & Komisi

```sql
CREATE TABLE fo_agents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    tipe ENUM('INTERNAL','EXTERNAL','REFERRAL') NOT NULL DEFAULT 'INTERNAL',
    level_id BIGINT UNSIGNED NULL,
    koor_id BIGINT UNSIGNED NULL,                            -- self-ref koordinator
    user_id BIGINT UNSIGNED NULL,                            -- jika agent juga user
    email VARCHAR(150) NULL,
    phone VARCHAR(30) NULL,
    npwp VARCHAR(30) NULL,
    bank_account VARCHAR(100) NULL,
    bank_id BIGINT UNSIGNED NULL,
    photo_path VARCHAR(255) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    resign_at DATE NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_code (tenant_id, code),
    FOREIGN KEY (koor_id) REFERENCES fo_agents(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (bank_id) REFERENCES banks(id)
);

CREATE TABLE fo_agent_targets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    period_year SMALLINT NOT NULL,
    period_month TINYINT NULL,                                -- NULL=annual
    target_units INT NULL,
    target_value DECIMAL(18,2) NULL,
    actual_units INT NOT NULL DEFAULT 0,
    actual_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_agent_period (agent_id, period_year, period_month, project_id),
    FOREIGN KEY (agent_id) REFERENCES fo_agents(id)
);
```

### 4.4 Reservasi & Kontrak

```sql
CREATE TABLE fo_reservasi (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     BIGINT UNSIGNED NOT NULL DEFAULT 1,
    no_reservasi  VARCHAR(50)  NOT NULL,                      -- auto-numbered
    customer_id   BIGINT UNSIGNED NOT NULL,
    unit_id       BIGINT UNSIGNED NOT NULL,
    project_id    BIGINT UNSIGNED NOT NULL,
    skema_id      BIGINT UNSIGNED NULL,
    price_list_id BIGINT UNSIGNED NULL,
    agent_id      BIGINT UNSIGNED NULL,
    tgl_reservasi DATE         NOT NULL,
    expire_date   DATE         NULL,                          -- batas reserve
    nominal_booking DECIMAL(18,2) NULL,
    nominal_dp    DECIMAL(18,2) NULL,
    harga_jual    DECIMAL(18,2) NOT NULL,
    diskon        DECIMAL(18,2) NULL,
    status        ENUM('ACTIVE','EXPIRED','UPGRADED_TO_KONTRAK','CANCELLED','REFUNDED') NOT NULL DEFAULT 'ACTIVE',
    notes         TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, no_reservasi),
    INDEX idx_status (status),
    INDEX idx_expire (expire_date),
    FOREIGN KEY (customer_id) REFERENCES fo_customers(id),
    FOREIGN KEY (unit_id) REFERENCES fo_units(id),
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (skema_id) REFERENCES fo_skema(id),
    FOREIGN KEY (agent_id) REFERENCES fo_agents(id)
);

CREATE TABLE fo_kontrak (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     BIGINT UNSIGNED NOT NULL DEFAULT 1,
    no_kontrak    VARCHAR(50)  NOT NULL,                      -- prefix KPR-/CASH-/dst
    reservasi_id  BIGINT UNSIGNED NULL,
    customer_id   BIGINT UNSIGNED NOT NULL,
    unit_id       BIGINT UNSIGNED NOT NULL,
    project_id    BIGINT UNSIGNED NOT NULL,
    company_id    BIGINT UNSIGNED NOT NULL,
    skema_id      BIGINT UNSIGNED NOT NULL,
    agent_id      BIGINT UNSIGNED NULL,
    tipe          ENUM('CASH','HARDCASH','CICILAN','KPR','KPA') NOT NULL,
    tgl_ppjb      DATE         NULL,
    tgl_ajb       DATE         NULL,
    tgl_akad_kpr  DATE         NULL,
    harga_kontrak DECIMAL(18,2) NOT NULL,
    diskon_total  DECIMAL(18,2) NULL DEFAULT 0,
    nominal_dp    DECIMAL(18,2) NULL,
    nominal_kpr_diajukan DECIMAL(18,2) NULL,                  -- nominal pengajuan KPR
    nominal_kpr_realisasi DECIMAL(18,2) NULL,                 -- approved bank
    ppn_status    ENUM('INCLUDE','EXCLUDE','BEBAS') NOT NULL DEFAULT 'EXCLUDE',
    pph_status    ENUM('INCLUDE','EXCLUDE','BEBAS') NOT NULL DEFAULT 'EXCLUDE',
    status        ENUM('DRAFT','ACTIVE','BATAL','LUNAS','BUYBACK','BAST') NOT NULL DEFAULT 'DRAFT',
    status_kpr    ENUM('N/A','PENGAJUAN','APPROVED','REJECTED','AKAD','REALISASI','LUNAS') NULL,
    notes         TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, no_kontrak),
    INDEX idx_status (status),
    INDEX idx_tgl_ppjb (tgl_ppjb),
    FOREIGN KEY (reservasi_id) REFERENCES fo_reservasi(id),
    FOREIGN KEY (customer_id) REFERENCES fo_customers(id),
    FOREIGN KEY (unit_id) REFERENCES fo_units(id),
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (skema_id) REFERENCES fo_skema(id),
    FOREIGN KEY (agent_id) REFERENCES fo_agents(id)
);

CREATE TABLE fo_tagihan (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kontrak_id   BIGINT UNSIGNED NOT NULL,
    urutan       SMALLINT NOT NULL,
    jenis        VARCHAR(50) NOT NULL,                       -- 'BOOKING','DP','CICILAN','PELUNASAN','RETENSI'
    nominal      DECIMAL(18,2) NOT NULL,
    nominal_ppn  DECIMAL(18,2) NULL,
    nominal_pph  DECIMAL(18,2) NULL,
    tgl_jatuh_tempo DATE NOT NULL,
    nominal_dibayar DECIMAL(18,2) NOT NULL DEFAULT 0,
    tgl_lunas    DATE NULL,
    status       ENUM('BELUM','SEBAGIAN','LUNAS','TELAT','BATAL') NOT NULL DEFAULT 'BELUM',
    notes        TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_kontrak (kontrak_id, urutan),
    INDEX idx_jatuh_tempo (tgl_jatuh_tempo, status),
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id) ON DELETE CASCADE
);
```

### 4.5 Finance AR (TTS, BKM, BG, VA, MEMO, FP, TTR)

```sql
CREATE TABLE fo_tts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    no_tts       VARCHAR(50) NOT NULL,
    kontrak_id   BIGINT UNSIGNED NULL,
    customer_id  BIGINT UNSIGNED NOT NULL,
    project_id   BIGINT UNSIGNED NOT NULL,
    company_id   BIGINT UNSIGNED NOT NULL,
    bank_id      BIGINT UNSIGNED NULL,                       -- akun bank tujuan
    tgl_tts      DATE NOT NULL,
    tipe_pembayaran ENUM('TUNAI','TRANSFER','GIRO','VA','XENDIT','DEBIT','KREDIT') NOT NULL,
    nominal      DECIMAL(18,2) NOT NULL,
    nominal_ppn  DECIMAL(18,2) NULL,
    nominal_pph  DECIMAL(18,2) NULL,
    reference    VARCHAR(100) NULL,                          -- no transfer / no giro
    bg_id        BIGINT UNSIGNED NULL,                       -- jika via giro
    va_id        BIGINT UNSIGNED NULL,                       -- jika via VA
    xendit_payment_id VARCHAR(100) NULL,
    status       ENUM('DRAFT','POSTED','VOID','MERGED') NOT NULL DEFAULT 'DRAFT',
    bkm_id       BIGINT UNSIGNED NULL,                       -- setelah posting
    nup_id       BIGINT UNSIGNED NULL,                       -- jika TTS untuk NUP
    notes        TEXT NULL,
    posted_at    TIMESTAMP NULL,
    voided_at    TIMESTAMP NULL,
    voided_reason TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, no_tts),
    INDEX idx_status (status),
    INDEX idx_tgl (tgl_tts),
    INDEX idx_customer (customer_id),
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id),
    FOREIGN KEY (customer_id) REFERENCES fo_customers(id),
    FOREIGN KEY (bank_id) REFERENCES banks(id)
);

CREATE TABLE fo_bkm (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    no_bkm       VARCHAR(50) NOT NULL,
    tgl_bkm      DATE NOT NULL,
    bank_id      BIGINT UNSIGNED NOT NULL,
    company_id   BIGINT UNSIGNED NOT NULL,
    total_nominal DECIMAL(18,2) NOT NULL,
    status       ENUM('DRAFT','POSTED','VOID') NOT NULL DEFAULT 'DRAFT',
    is_synced_to_bo BOOLEAN NOT NULL DEFAULT FALSE,
    is_synced_to_bankloan BOOLEAN NOT NULL DEFAULT FALSE,
    notes        TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, no_bkm),
    INDEX idx_tgl (tgl_bkm),
    INDEX idx_synced (is_synced_to_bo, is_synced_to_bankloan),
    FOREIGN KEY (bank_id) REFERENCES banks(id)
);

CREATE TABLE fo_bkm_details (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bkm_id       BIGINT UNSIGNED NOT NULL,
    tts_id       BIGINT UNSIGNED NOT NULL,
    nominal      DECIMAL(18,2) NOT NULL,
    UNIQUE KEY uq_bkm_tts (bkm_id, tts_id),
    FOREIGN KEY (bkm_id) REFERENCES fo_bkm(id) ON DELETE CASCADE,
    FOREIGN KEY (tts_id) REFERENCES fo_tts(id)
);

CREATE TABLE fo_bg (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    no_bg        VARCHAR(50) NOT NULL,                       -- internal
    no_bg_cetak  VARCHAR(50) NULL,                           -- nomor di lembar BG
    bank_id      BIGINT UNSIGNED NOT NULL,                   -- bank penerbit
    customer_id  BIGINT UNSIGNED NULL,
    tgl_terbit   DATE NULL,
    tgl_efektif  DATE NULL,
    nominal      DECIMAL(18,2) NOT NULL,
    status       ENUM('PENDING','KLIRING','TOLAK','GANTI','VOID') NOT NULL DEFAULT 'PENDING',
    tolakan_reason TEXT NULL,
    ganti_with_bg_id BIGINT UNSIGNED NULL,
    notes        TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, no_bg),
    INDEX idx_status (status),
    INDEX idx_efektif (tgl_efektif),
    FOREIGN KEY (bank_id) REFERENCES banks(id),
    FOREIGN KEY (customer_id) REFERENCES fo_customers(id)
);

CREATE TABLE fo_va (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    no_va        VARCHAR(30) NOT NULL,                       -- 16-19 digit VA number
    customer_id  BIGINT UNSIGNED NOT NULL,
    kontrak_id   BIGINT UNSIGNED NULL,
    bank_code    ENUM('BCA','BNI','BRI','MANDIRI','PERMATA','BSI') NOT NULL,
    nominal_default DECIMAL(18,2) NULL,
    tgl_aktif    DATE NULL,
    tgl_nonaktif DATE NULL,
    is_open_amount BOOLEAN NOT NULL DEFAULT FALSE,
    status       ENUM('ACTIVE','INACTIVE','EXPIRED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_no_va (no_va),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    FOREIGN KEY (customer_id) REFERENCES fo_customers(id),
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id)
);

CREATE TABLE fo_memo (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    no_memo      VARCHAR(50) NOT NULL,
    customer_id  BIGINT UNSIGNED NOT NULL,
    kontrak_id   BIGINT UNSIGNED NULL,
    tgl_memo     DATE NOT NULL,
    jenis        ENUM('CASHBACK','KOREKSI','REFUND','POTONGAN','GIMMICK') NOT NULL,
    nominal      DECIMAL(18,2) NOT NULL,
    status       ENUM('DRAFT','POSTED','VOID') NOT NULL DEFAULT 'DRAFT',
    keterangan   TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, no_memo),
    FOREIGN KEY (customer_id) REFERENCES fo_customers(id),
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id)
);

CREATE TABLE fo_fp (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    no_fp        VARCHAR(50) NOT NULL,                       -- internal numbering
    nomor_seri_fp VARCHAR(20) NOT NULL,                      -- 16-digit DJP
    kontrak_id   BIGINT UNSIGNED NULL,
    customer_id  BIGINT UNSIGNED NULL,
    tgl_fp       DATE NOT NULL,
    dpp_nominal  DECIMAL(18,2) NOT NULL,
    ppn_nominal  DECIMAL(18,2) NOT NULL,
    pph_nominal  DECIMAL(18,2) NULL,
    status_efaktur ENUM('DRAFT','UPLOADED','APPROVED','REJECTED','CANCELED') NOT NULL DEFAULT 'DRAFT',
    file_csv_path VARCHAR(255) NULL,
    response_djp_json JSON NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, no_fp),
    UNIQUE KEY uq_nomor_seri (nomor_seri_fp),
    INDEX idx_status (status_efaktur),
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id),
    FOREIGN KEY (customer_id) REFERENCES fo_customers(id)
);

CREATE TABLE fo_ttr (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    no_ttr       VARCHAR(50) NOT NULL,
    tts_id       BIGINT UNSIGNED NOT NULL,
    tgl_cetak    DATETIME NOT NULL,
    printed_by   BIGINT UNSIGNED NULL,
    print_count  INT NOT NULL DEFAULT 1,
    UNIQUE KEY uq_no_ttr (no_ttr),
    FOREIGN KEY (tts_id) REFERENCES fo_tts(id)
);

CREATE TABLE fo_transfer_anonim (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    bank_id      BIGINT UNSIGNED NOT NULL,
    tgl_masuk    DATE NOT NULL,
    nominal      DECIMAL(18,2) NOT NULL,
    berita       TEXT NULL,
    rekening_pengirim VARCHAR(100) NULL,
    nama_pengirim VARCHAR(150) NULL,
    status_alokasi ENUM('UNALLOCATED','ALLOCATED','REFUNDED') NOT NULL DEFAULT 'UNALLOCATED',
    alokasi_customer_id BIGINT UNSIGNED NULL,
    alokasi_kontrak_id BIGINT UNSIGNED NULL,
    alokasi_tts_id BIGINT UNSIGNED NULL,
    alokasi_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (bank_id) REFERENCES banks(id)
);
```

### 4.6 Collection

```sql
CREATE TABLE fo_pjt (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    no_pjt       VARCHAR(50) NOT NULL,
    kontrak_id   BIGINT UNSIGNED NOT NULL,
    tgl_cetak    DATETIME NOT NULL,
    tagihan_ids  JSON NOT NULL,                              -- list ID tagihan yang dicover
    total_tunggakan DECIMAL(18,2) NOT NULL,
    sent_via     SET('PRINT','EMAIL','WHATSAPP','SMS') NOT NULL,
    created_at TIMESTAMP NULL,
    UNIQUE KEY uq_no (no_pjt),
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id)
);

CREATE TABLE fo_skl (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    no_skl       VARCHAR(50) NOT NULL,
    kontrak_id   BIGINT UNSIGNED NOT NULL,
    tgl_lunas    DATE NOT NULL,
    tgl_cetak    DATETIME NOT NULL,
    created_at TIMESTAMP NULL,
    UNIQUE KEY uq_no (no_skl),
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id)
);

CREATE TABLE fo_followup (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id  BIGINT UNSIGNED NOT NULL,
    kontrak_id   BIGINT UNSIGNED NULL,
    user_id      BIGINT UNSIGNED NOT NULL,                   -- collector
    tgl_fu       DATETIME NOT NULL,
    jenis        ENUM('TELEPON','WHATSAPP','EMAIL','SURAT','KUNJUNGAN') NOT NULL,
    hasil        VARCHAR(100) NULL,                          -- 'JANJI_BAYAR','TIDAK_RESPON','REJECT','BATAL'
    janji_bayar_date DATE NULL,
    janji_bayar_amount DECIMAL(18,2) NULL,
    next_fu_date DATE NULL,
    catatan      TEXT NULL,
    grouping_id  BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_customer (customer_id, tgl_fu DESC),
    FOREIGN KEY (customer_id) REFERENCES fo_customers(id),
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### 4.7 Legal

```sql
CREATE TABLE fo_legal_ppjb (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kontrak_id   BIGINT UNSIGNED NOT NULL,
    no_ppjb      VARCHAR(50) NOT NULL,
    tgl_ppjb     DATE NULL,
    tgl_ttd_target DATE NULL,
    tgl_ttd_aktual DATE NULL,
    notaris      VARCHAR(150) NULL,
    file_path    VARCHAR(255) NULL,
    status       ENUM('PROSES','TTD','BATAL') NOT NULL DEFAULT 'PROSES',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_kontrak (kontrak_id),                       -- 1 kontrak = 1 PPJB
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id)
);

-- analog: fo_legal_ajb, fo_legal_st (BAST), fo_legal_imb, fo_legal_sertifikat
-- (skema mirror dengan tambahan kolom domain-spesifik)
```

### 4.8 Launching, NUP

```sql
CREATE TABLE fo_launching_events (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    project_id   BIGINT UNSIGNED NOT NULL,
    code         VARCHAR(50) NOT NULL,
    name         VARCHAR(200) NOT NULL,
    tanggal      DATE NOT NULL,
    venue        VARCHAR(200) NULL,
    nominal_nup  DECIMAL(18,2) NULL,
    max_nup_per_customer SMALLINT NULL,
    config_json  JSON NULL,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_project_code (project_id, code),
    FOREIGN KEY (project_id) REFERENCES projects(id)
);

CREATE TABLE fo_nup (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    no_nup       VARCHAR(30) NOT NULL,                       -- prefix NUP- atau project-specific
    no_urut      INT NOT NULL,                                -- 1, 2, 3, ...
    launching_event_id BIGINT UNSIGNED NULL,
    customer_id  BIGINT UNSIGNED NOT NULL,
    project_id   BIGINT UNSIGNED NOT NULL,
    tgl_daftar   DATE NOT NULL,
    tgl_bayar    DATE NULL,
    nominal_nup  DECIMAL(18,2) NOT NULL,
    tts_id       BIGINT UNSIGNED NULL,                       -- TTS pembayaran NUP
    status       ENUM('DAFTAR','LUNAS','DIPAKAI','BATAL','REFUND','EXPIRED') NOT NULL DEFAULT 'DAFTAR',
    kontrak_id   BIGINT UNSIGNED NULL,                       -- jika sudah closing
    refund_tts_id BIGINT UNSIGNED NULL,
    source       ENUM('INTERNAL','PUBLIC_WEB') NOT NULL DEFAULT 'INTERNAL',
    ip_register  VARCHAR(45) NULL,
    notes        TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, no_nup),
    UNIQUE KEY uq_event_urut (launching_event_id, no_urut),
    INDEX idx_status (status),
    FOREIGN KEY (customer_id) REFERENCES fo_customers(id),
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (launching_event_id) REFERENCES fo_launching_events(id),
    FOREIGN KEY (tts_id) REFERENCES fo_tts(id),
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id)
);

CREATE TABLE fo_nup_reception (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nup_id       BIGINT UNSIGNED NOT NULL,
    tgl_hadir    DATETIME NOT NULL,
    status       ENUM('HADIR','HOLD','UNHOLD','CANCELLED','SELECTED','EXPIRED') NOT NULL,
    table_no     VARCHAR(20) NULL,
    user_id      BIGINT UNSIGNED NULL,                       -- kasir / sales
    notes        TEXT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_nup (nup_id, tgl_hadir),
    FOREIGN KEY (nup_id) REFERENCES fo_nup(id)
);
```

### 4.9 Approval

```sql
CREATE TABLE fo_approvals (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    kontrak_id   BIGINT UNSIGNED NULL,                       -- approval kontrak-related
    reference_type VARCHAR(50) NULL,                         -- jika non-kontrak
    reference_id BIGINT UNSIGNED NULL,
    jenis        ENUM('ADJUSTMENT','BATAL','CUSTOM_TAGIHAN','DISKON','GANTI_NAMA','GANTI_UNIT','RESCHEDULE','REFUND') NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    requested_at TIMESTAMP NOT NULL,
    request_payload JSON NULL,                               -- detail before/after
    status       ENUM('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    approver_level TINYINT NULL,                             -- multi-level: 1,2,3
    approved_by  BIGINT UNSIGNED NULL,
    approved_at  TIMESTAMP NULL,
    rejected_by  BIGINT UNSIGNED NULL,
    rejected_at  TIMESTAMP NULL,
    rejected_reason TEXT NULL,
    notes        TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_jenis (jenis),
    INDEX idx_kontrak (kontrak_id),
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id)
);
```

### 4.10 Komisi

```sql
CREATE TABLE fo_komisi (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    no_komisi    VARCHAR(50) NOT NULL,
    agent_id     BIGINT UNSIGNED NOT NULL,
    kontrak_id   BIGINT UNSIGNED NOT NULL,
    skema_komisi VARCHAR(50) NULL,
    nominal_dasar DECIMAL(18,2) NOT NULL,                    -- harga kontrak / DP
    persentase   DECIMAL(7,4) NULL,
    nominal      DECIMAL(18,2) NOT NULL,
    nominal_pajak DECIMAL(18,2) NULL,
    tipe         ENUM('CLOSING_FEE','KOMISI','REWARD') NOT NULL,
    status       ENUM('DRAFT','APPROVED','CAIRED','BATAL') NOT NULL DEFAULT 'DRAFT',
    tgl_cair     DATE NULL,
    bukti_cair_id BIGINT UNSIGNED NULL,                      -- FK ke TTS / BKK
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, no_komisi),
    FOREIGN KEY (agent_id) REFERENCES fo_agents(id),
    FOREIGN KEY (kontrak_id) REFERENCES fo_kontrak(id)
);

CREATE TABLE fo_komisi_terms (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    komisi_id    BIGINT UNSIGNED NOT NULL,
    urutan       SMALLINT NOT NULL,
    nominal      DECIMAL(18,2) NOT NULL,
    tgl_jadwal   DATE NOT NULL,
    tgl_cair     DATE NULL,
    status       ENUM('PENDING','CAIRED','BATAL') NOT NULL DEFAULT 'PENDING',
    FOREIGN KEY (komisi_id) REFERENCES fo_komisi(id) ON DELETE CASCADE
);
```

---

## 5. BO — Back Office (Accounting)

### 5.1 Chart of Account & Jurnal

```sql
CREATE TABLE bo_accounts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    company_id   BIGINT UNSIGNED NOT NULL,
    code         VARCHAR(30) NOT NULL,                       -- '1.1.01.001'
    name         VARCHAR(200) NOT NULL,
    tipe_akun    ENUM('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE') NOT NULL,
    parent_id    BIGINT UNSIGNED NULL,
    level        TINYINT NOT NULL DEFAULT 1,
    is_header    BOOLEAN NOT NULL DEFAULT FALSE,             -- header tidak bisa di-jurnal
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    is_pajak     BOOLEAN NOT NULL DEFAULT FALSE,
    normal_balance ENUM('D','C') NOT NULL,                   -- Debit/Credit
    settings_json JSON NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_company_code (company_id, code),
    INDEX idx_parent (parent_id),
    INDEX idx_tipe (tipe_akun),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (parent_id) REFERENCES bo_accounts(id)
);

CREATE TABLE bo_jurnal_header (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    company_id   BIGINT UNSIGNED NOT NULL,
    no_jurnal    VARCHAR(50) NOT NULL,
    tgl_jurnal   DATE NOT NULL,
    tipe         ENUM('UMUM','AR','AP','CB','IVT','HR','CLOSING','FO_BO_BRIDGE') NOT NULL,
    reference_type VARCHAR(50) NULL,                         -- 'tts','bkm','invoice_ap', ...
    reference_id BIGINT UNSIGNED NULL,
    description  TEXT NULL,
    total_debit  DECIMAL(18,2) NOT NULL,
    total_credit DECIMAL(18,2) NOT NULL,
    status       ENUM('DRAFT','APPROVED','POSTED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
    period_year  SMALLINT NOT NULL,
    period_month TINYINT NOT NULL,
    is_locked    BOOLEAN NOT NULL DEFAULT FALSE,             -- locked setelah closing periode
    posted_at    TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    approved_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, no_jurnal),
    INDEX idx_period (period_year, period_month),
    INDEX idx_status (status),
    INDEX idx_reference (reference_type, reference_id),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);

CREATE TABLE bo_jurnal_detail (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jurnal_id    BIGINT UNSIGNED NOT NULL,
    urutan       SMALLINT NOT NULL,
    account_id   BIGINT UNSIGNED NOT NULL,
    cost_center_id BIGINT UNSIGNED NULL,
    project_id   BIGINT UNSIGNED NULL,
    debit        DECIMAL(18,2) NOT NULL DEFAULT 0,
    credit       DECIMAL(18,2) NOT NULL DEFAULT 0,
    description  TEXT NULL,
    INDEX idx_account (account_id),
    INDEX idx_jurnal (jurnal_id, urutan),
    FOREIGN KEY (jurnal_id) REFERENCES bo_jurnal_header(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES bo_accounts(id),
    FOREIGN KEY (cost_center_id) REFERENCES cost_centers(id),
    FOREIGN KEY (project_id) REFERENCES projects(id)
);

CREATE TABLE bo_periode (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id   BIGINT UNSIGNED NOT NULL,
    year         SMALLINT NOT NULL,
    month        TINYINT NOT NULL,
    status       ENUM('OPEN','CLOSING_PROCESS','CLOSED','LOCKED') NOT NULL DEFAULT 'OPEN',
    closed_at    TIMESTAMP NULL,
    closed_by    BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_company_period (company_id, year, month),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
```

### 5.2 AP, AR, Cash & Bank (ringkas, pattern mirror)

```sql
-- AP
CREATE TABLE bo_invoice_ap (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    company_id BIGINT UNSIGNED NOT NULL,
    no_invoice VARCHAR(50) NOT NULL,
    supplier_id BIGINT UNSIGNED NULL,
    tgl_invoice DATE NOT NULL,
    tgl_jatuh_tempo DATE NULL,
    nominal_dpp DECIMAL(18,2) NOT NULL,
    nominal_ppn DECIMAL(18,2) NULL,
    nominal_pph DECIMAL(18,2) NULL,
    nominal_total DECIMAL(18,2) NOT NULL,
    nominal_dibayar DECIMAL(18,2) NOT NULL DEFAULT 0,
    status ENUM('DRAFT','POSTED','PARTIAL','LUNAS','VOID') NOT NULL DEFAULT 'DRAFT',
    jenis ENUM('PO','NON_PO','SPK') NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, no_invoice),
    INDEX idx_status (status),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);

-- analog: bo_kuitansi, bo_memorial, bo_ttg, bo_bpph, bo_aging_ap (view)

-- AR (mirror dari FO via FO-BO bridge)
CREATE TABLE bo_invoice_ar (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- similar pattern
);

-- CB: bo_bkm, bo_bkk, bo_bgm, bo_bgk, bo_bng, bo_bs, bo_adm, bo_ano, bo_pbs, bo_rbs, bo_tr
-- semua punya: id, no_*, tgl, bank_id, total_nominal, status, jurnal_id, ...

-- IVT
CREATE TABLE bo_master_stok (...);
CREATE TABLE bo_item_kategori (...);
CREATE TABLE bo_lokasi_gudang (...);
CREATE TABLE bo_fpb (...);          -- Form Permintaan Barang
CREATE TABLE bo_fpb_detail (...);
CREATE TABLE bo_pr (...);           -- Purchase Request
CREATE TABLE bo_po (...);           -- Purchase Order
CREATE TABLE bo_bkb (...);          -- Bukti Keluar Barang
CREATE TABLE bo_bpb (...);          -- Bukti Penerimaan Barang
CREATE TABLE bo_stok_mutasi (...);  -- ledger stok

-- HR
CREATE TABLE bo_karyawan (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    company_id BIGINT UNSIGNED NOT NULL,
    nik VARCHAR(30) NOT NULL,                                 -- employee number
    full_name VARCHAR(150) NOT NULL,
    dept_id BIGINT UNSIGNED NULL,
    jabatan VARCHAR(100) NULL,
    user_id BIGINT UNSIGNED NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    -- ... biodata
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_company_nik (company_id, nik)
);

CREATE TABLE bo_brq (...);          -- Budget Request
```

### 5.3 Supplier (untuk AP)

```sql
CREATE TABLE bo_suppliers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(200) NOT NULL,
    address TEXT NULL,
    city VARCHAR(100) NULL,
    npwp VARCHAR(30) NULL,
    pic VARCHAR(150) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    bank_id BIGINT UNSIGNED NULL,
    bank_account VARCHAR(100) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_code (tenant_id, code),
    FOREIGN KEY (bank_id) REFERENCES banks(id)
);
```

---

## 6. BankLoan (KPR)

Schema ini adalah port dari **103 EF Core migrations** existing (lihat [ERD.md §3](ERD.md)). Tabel kunci:

```sql
CREATE TABLE bankloan_customers (
    -- mirror dari fo_customers tapi dengan field KPR-spesifik
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    fo_customer_id BIGINT UNSIGNED NULL,                     -- link ke FO customer
    code VARCHAR(30) NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    nik VARCHAR(30) NOT NULL,
    occupation_id BIGINT UNSIGNED NULL,
    income_id BIGINT UNSIGNED NULL,
    monthly_income DECIMAL(18,2) NULL,
    spouse_name VARCHAR(200) NULL,
    spouse_nik VARCHAR(30) NULL,
    spouse_income DECIMAL(18,2) NULL,
    family_dependents TINYINT NULL,
    -- ... biodata KPR-specific
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_code (tenant_id, code),
    INDEX idx_nik (nik),
    FOREIGN KEY (fo_customer_id) REFERENCES fo_customers(id)
);

CREATE TABLE bankloan_occupations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE bankloan_incomes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    range_label VARCHAR(100) NOT NULL UNIQUE,                -- '5-10 juta'
    min_value DECIMAL(18,2) NULL,
    max_value DECIMAL(18,2) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE bankloan_contracts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    fo_kontrak_id BIGINT UNSIGNED NULL,                      -- link to FO kontrak
    contract_number VARCHAR(50) NOT NULL,                    -- 'KPR-...'
    booking_number VARCHAR(50) NULL,
    booking_date DATE NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    unit_code VARCHAR(50) NULL,
    unit_address VARCHAR(255) NULL,
    sales_person_name VARCHAR(150) NULL,
    total_price DECIMAL(18,2) NOT NULL,
    down_payment DECIMAL(18,2) NULL,
    submission_amount DECIMAL(18,2) NULL,
    active_status ENUM('ACTIVE','INACTIVE','CANCELLED','SETTLED') NOT NULL DEFAULT 'ACTIVE',
    buyback_status ENUM('NONE','REQUESTED','APPROVED','POSTED','CANCELLED') NOT NULL DEFAULT 'NONE',
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, contract_number),
    INDEX idx_active_status (active_status),
    INDEX idx_buyback_status (buyback_status),
    FOREIGN KEY (fo_kontrak_id) REFERENCES fo_kontrak(id),
    FOREIGN KEY (customer_id) REFERENCES bankloan_customers(id),
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);

CREATE TABLE bankloan_contract_pre_interview (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL,
    fund_source VARCHAR(100) NULL,
    purchasing_purpose VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_contract (contract_id),
    FOREIGN KEY (contract_id) REFERENCES bankloan_contracts(id) ON DELETE CASCADE
);

CREATE TABLE bankloan_bi_checkings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL,
    result ENUM('LANCAR','DPK','KURANG_LANCAR','DIRAGUKAN','MACET','UNKNOWN') NOT NULL,
    checked_at DATE NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (contract_id) REFERENCES bankloan_contracts(id) ON DELETE CASCADE
);

CREATE TABLE bankloan_bi_checking_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bi_checking_id BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NULL,
    file_size INT NULL,
    uploaded_at TIMESTAMP NOT NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    FOREIGN KEY (bi_checking_id) REFERENCES bankloan_bi_checkings(id) ON DELETE CASCADE
);

CREATE TABLE bankloan_contract_banks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL,
    bank_id BIGINT UNSIGNED NOT NULL,
    requirement_status VARCHAR(50) NULL,
    approved_amount DECIMAL(18,2) NULL,
    approved_description TEXT NULL,
    status ENUM('PENGAJUAN','PROSES','APPROVED','REJECTED','AKAD','REALISASI','LUNAS','CANCELLED') NOT NULL DEFAULT 'PENGAJUAN',
    filing_date DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    INDEX idx_contract (contract_id),
    INDEX idx_status (status, filing_date),
    FOREIGN KEY (contract_id) REFERENCES bankloan_contracts(id),
    FOREIGN KEY (bank_id) REFERENCES banks(id)
);

CREATE TABLE bankloan_requirements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE bankloan_bank_requirements (
    bank_id BIGINT UNSIGNED NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL,
    is_mandatory BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (bank_id, requirement_id),
    FOREIGN KEY (bank_id) REFERENCES banks(id),
    FOREIGN KEY (requirement_id) REFERENCES bankloan_requirements(id)
);

CREATE TABLE bankloan_contract_bank_requirements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_bank_id BIGINT UNSIGNED NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL,
    filing_date DATE NULL,
    file_path VARCHAR(255) NULL,
    original_file_name VARCHAR(255) NULL,
    mime_type VARCHAR(100) NULL,
    status ENUM('BELUM','UPLOADED','VERIFIED','REJECTED') NOT NULL DEFAULT 'BELUM',
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_cb (contract_bank_id),
    FOREIGN KEY (contract_bank_id) REFERENCES bankloan_contract_banks(id) ON DELETE CASCADE,
    FOREIGN KEY (requirement_id) REFERENCES bankloan_requirements(id)
);

CREATE TABLE bankloan_contract_bank_interviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_bank_id BIGINT UNSIGNED NOT NULL,
    interview_date DATE NOT NULL,
    result ENUM('LULUS','TIDAK_LULUS','PERLU_TINDAK_LANJUT') NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (contract_bank_id) REFERENCES bankloan_contract_banks(id) ON DELETE CASCADE
);

CREATE TABLE bankloan_contract_bank_sp3k (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_bank_id BIGINT UNSIGNED NOT NULL,
    sp3k_number VARCHAR(100) NULL,
    sp3k_date DATE NULL,
    result ENUM('APPROVED','REJECTED','PERLU_REVISI') NULL,
    approved_amount DECIMAL(18,2) NULL,
    notes TEXT NULL,
    file_path VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (contract_bank_id) REFERENCES bankloan_contract_banks(id) ON DELETE CASCADE
);

CREATE TABLE bankloan_contract_bank_agreements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_bank_id BIGINT UNSIGNED NOT NULL,
    agreement_number VARCHAR(100) NOT NULL,
    agreement_date DATE NOT NULL,
    notary VARCHAR(200) NULL,
    disbursement_type ENUM('ONE_TIME','TERMIN','MIXED') NOT NULL,
    file_path VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (contract_bank_id) REFERENCES bankloan_contract_banks(id) ON DELETE CASCADE
);

CREATE TABLE bankloan_retention_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE bankloan_bank_retention_schemes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_id BIGINT UNSIGNED NOT NULL,
    retention_category_id BIGINT UNSIGNED NOT NULL,
    persentase DECIMAL(5,2) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_bank_cat (bank_id, retention_category_id),
    FOREIGN KEY (bank_id) REFERENCES banks(id),
    FOREIGN KEY (retention_category_id) REFERENCES bankloan_retention_categories(id)
);

CREATE TABLE bankloan_contract_bank_retention (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_bank_id BIGINT UNSIGNED NOT NULL,
    retention_category_id BIGINT UNSIGNED NOT NULL,
    amount_held DECIMAL(18,2) NOT NULL,
    scheduled_release_date DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (contract_bank_id) REFERENCES bankloan_contract_banks(id),
    FOREIGN KEY (retention_category_id) REFERENCES bankloan_retention_categories(id)
);

CREATE TABLE bankloan_agreement_realization (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    number VARCHAR(50) NOT NULL,                              -- 'RA-...'
    contract_bank_agreement_id BIGINT UNSIGNED NOT NULL,
    realization_date DATE NOT NULL,
    disbursed_amount DECIMAL(18,2) NOT NULL,
    excess_realization_amount DECIMAL(18,2) NULL,
    status ENUM('DRAFT','APPROVED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, number),
    FOREIGN KEY (contract_bank_agreement_id) REFERENCES bankloan_contract_bank_agreements(id)
);

CREATE TABLE bankloan_retention_submission (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    number VARCHAR(50) NOT NULL,                              -- 'PR-...'
    contract_bank_retention_id BIGINT UNSIGNED NOT NULL,
    contract_number VARCHAR(50) NULL,                         -- denormalized
    sales_person_name VARCHAR(150) NULL,                      -- denormalized
    submission_date DATE NOT NULL,
    requested_amount DECIMAL(18,2) NOT NULL,
    status ENUM('DRAFT','SUBMITTED','APPROVED','REJECTED','PAID') NOT NULL DEFAULT 'DRAFT',
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, number),
    FOREIGN KEY (contract_bank_retention_id) REFERENCES bankloan_contract_bank_retention(id)
);

CREATE TABLE bankloan_retention_realization (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    number VARCHAR(50) NOT NULL,                              -- 'RR-...'
    retention_submission_id BIGINT UNSIGNED NOT NULL,
    realization_date DATE NOT NULL,
    realized_amount DECIMAL(18,2) NOT NULL,
    remaining_amount DECIMAL(18,2) NULL,                      -- sisa di retensi
    status ENUM('DRAFT','APPROVED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_no (tenant_id, number),
    FOREIGN KEY (retention_submission_id) REFERENCES bankloan_retention_submission(id)
);

CREATE TABLE bankloan_mom (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    title VARCHAR(255) NOT NULL,
    meeting_date DATE NOT NULL,
    bank_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    description TEXT NULL,
    file_path VARCHAR(255) NULL,
    attendees JSON NULL,                                       -- list nama hadir
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (bank_id) REFERENCES banks(id),
    FOREIGN KEY (project_id) REFERENCES projects(id)
);

CREATE TABLE bankloan_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    project_id BIGINT UNSIGNED NULL,
    code VARCHAR(50) NOT NULL,                                -- 'PPJB','AJB','SP3K','AKAD', ...
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    template_body LONGTEXT NULL,                              -- Blade template
    template_engine ENUM('BLADE','HANDLEBARS') NOT NULL DEFAULT 'BLADE',
    pdf_options_json JSON NULL,                               -- size, margin, orientation
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_code (tenant_id, code)
);

CREATE TABLE bankloan_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    type VARCHAR(50) NOT NULL,
    reference_id VARCHAR(100) NULL,                           -- string ref (per "AddNotificationReferenceId")
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NULL,
    INDEX idx_user_read (user_id, is_read, created_at DESC),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

---

## 7. Backup (TuxArch Replacement)

```sql
CREATE TABLE backup_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    cron_expression VARCHAR(50) NOT NULL,                     -- '30 0 * * *' (00:30 daily)
    backup_type ENUM('FULL','INCREMENTAL') NOT NULL DEFAULT 'FULL',
    include_files BOOLEAN NOT NULL DEFAULT TRUE,             -- include storage files
    compress_to_zip BOOLEAN NOT NULL DEFAULT TRUE,
    storage_disk VARCHAR(50) NOT NULL DEFAULT 'local',       -- Laravel disk: local, s3, minio
    storage_path VARCHAR(255) NOT NULL,                       -- target path
    retention_days INT NOT NULL DEFAULT 365,
    auto_shrink_before BOOLEAN NOT NULL DEFAULT FALSE,
    notify_on_success BOOLEAN NOT NULL DEFAULT FALSE,
    notify_on_failure BOOLEAN NOT NULL DEFAULT TRUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_run_at TIMESTAMP NULL,
    next_run_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_name (tenant_id, name)
);

CREATE TABLE backup_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id BIGINT UNSIGNED NULL,
    started_at TIMESTAMP NOT NULL,
    finished_at TIMESTAMP NULL,
    status ENUM('RUNNING','SUCCESS','FAILED','PARTIAL') NOT NULL DEFAULT 'RUNNING',
    backup_type ENUM('FULL','INCREMENTAL','ON_DEMAND') NOT NULL,
    file_path VARCHAR(500) NULL,                              -- path file backup
    file_size_bytes BIGINT NULL,
    file_hash VARCHAR(64) NULL,                               -- SHA-256 untuk integrity check
    duration_seconds INT NULL,
    error_message TEXT NULL,
    triggered_by BIGINT UNSIGNED NULL,                        -- NULL=scheduler, user_id=manual
    metadata_json JSON NULL,                                  -- detail per-table size, dst.
    created_at TIMESTAMP NULL,
    INDEX idx_status_started (status, started_at DESC),
    INDEX idx_schedule (schedule_id, started_at DESC),
    FOREIGN KEY (schedule_id) REFERENCES backup_schedules(id) ON DELETE SET NULL
);

CREATE TABLE backup_alert_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backup_log_id BIGINT UNSIGNED NULL,
    alert_type ENUM('EMAIL','TRELLO','WHATSAPP','WEBHOOK') NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    sent_at TIMESTAMP NOT NULL,
    is_success BOOLEAN NOT NULL,
    error_message TEXT NULL,
    FOREIGN KEY (backup_log_id) REFERENCES backup_logs(id) ON DELETE CASCADE
);

-- backup_configs: pakai tabel settings (Spatie) dengan group='backup'
```

---

## 8. NUP Public Registration

Reuse `fo_customers` + `fo_nup` dengan source `PUBLIC_WEB`. Plus log audit khusus:

```sql
CREATE TABLE fo_nup_public_registrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    nup_id BIGINT UNSIGNED NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    sumber_data_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    recaptcha_score DECIMAL(3,2) NULL,                        -- reCAPTCHA v3 score
    ktp_file_path VARCHAR(255) NULL,
    status ENUM('PENDING_PAYMENT','VERIFIED','REJECTED','SPAM') NOT NULL DEFAULT 'PENDING_PAYMENT',
    verified_at TIMESTAMP NULL,
    verified_by BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_ip (ip_address),
    FOREIGN KEY (customer_id) REFERENCES fo_customers(id),
    FOREIGN KEY (nup_id) REFERENCES fo_nup(id),
    FOREIGN KEY (project_id) REFERENCES projects(id)
);
```

---

## 9. Sync Events (FO → BO, FO → BankLoan)

Bukan tabel, tapi **event listener** Laravel. Konvensi:

```php
// app/Events/Fo/KontrakCreated.php
class KontrakCreated { public Kontrak $kontrak; }

// app/Listeners/SyncKontrakToBankLoan.php
class SyncKontrakToBankLoan implements ShouldQueue
{
    public function handle(KontrakCreated $event): void
    {
        // Mirror ke bankloan_customers (jika belum) + bankloan_contracts
        // dispatch via Horizon queue: 'sync'
    }
}

// app/Listeners/PostJurnalFOToBO.php
class PostJurnalFOToBO implements ShouldQueue
{
    public function handle(TtsPosted|BkmPosted $event): void
    {
        // Generate bo_jurnal_header + details berdasarkan mapping di settings
    }
}
```

Untuk tracking sync state:

```sql
CREATE TABLE sync_events_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(150) NOT NULL,
    source_module VARCHAR(50) NOT NULL,                       -- 'fo'
    target_module VARCHAR(50) NOT NULL,                       -- 'bo' | 'bankloan'
    source_type VARCHAR(50) NOT NULL,                         -- 'fo_kontrak'
    source_id BIGINT UNSIGNED NOT NULL,
    target_type VARCHAR(50) NULL,
    target_id BIGINT UNSIGNED NULL,
    status ENUM('PENDING','SUCCESS','FAILED','RETRYING') NOT NULL DEFAULT 'PENDING',
    payload JSON NULL,
    error_message TEXT NULL,
    retry_count TINYINT NOT NULL DEFAULT 0,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_status_event (status, event_name),
    INDEX idx_source (source_type, source_id)
);
```

---

## 10. Indeks & Performance

### 10.1 Composite indexes critical

```sql
-- FO Kontrak
ALTER TABLE fo_kontrak ADD INDEX idx_project_status_tgl (project_id, status, tgl_ppjb DESC);
ALTER TABLE fo_kontrak ADD INDEX idx_customer_status (customer_id, status);

-- FO Tagihan (untuk reminder & collection)
ALTER TABLE fo_tagihan ADD INDEX idx_jatuh_tempo_belum (tgl_jatuh_tempo, status) USING BTREE;

-- FO TTS (dashboard finance)
ALTER TABLE fo_tts ADD INDEX idx_project_tgl_status (project_id, tgl_tts DESC, status);

-- FO BKM (cross-reference)
ALTER TABLE fo_bkm ADD INDEX idx_synced (is_synced_to_bo, is_synced_to_bankloan, created_at);

-- FO VA
ALTER TABLE fo_va ADD INDEX idx_bank_status (bank_code, status);

-- BankLoan Contract
ALTER TABLE bankloan_contracts ADD INDEX idx_project_active (project_id, active_status);
ALTER TABLE bankloan_contract_banks ADD INDEX idx_status_filing (status, filing_date);

-- BO Jurnal
ALTER TABLE bo_jurnal_header ADD INDEX idx_period_status (period_year, period_month, status);
ALTER TABLE bo_jurnal_detail ADD INDEX idx_account_jurnal (account_id, jurnal_id);

-- Activity log (Spatie default)
ALTER TABLE activity_log ADD INDEX idx_causer_created (causer_id, created_at DESC);
```

### 10.2 Fulltext search

```sql
-- Customer search (autocomplete dropdown)
ALTER TABLE fo_customers ADD FULLTEXT ft_customer (full_name, email, phone, nik);

-- Unit search
ALTER TABLE fo_units ADD FULLTEXT ft_unit (code, cluster, block, no_unit);

-- Kontrak search
ALTER TABLE fo_kontrak ADD FULLTEXT ft_kontrak (no_kontrak, notes);
```

### 10.3 Partitioning (untuk tabel besar)

Tabel yang growth-heavy:
- `activity_log` → partition by `RANGE (YEAR(created_at))` per tahun
- `fo_tts`, `fo_bkm` → partition by `RANGE (YEAR(tgl_tts))` jika >1M rows
- `backup_logs` → partition by `RANGE (YEAR(started_at))`
- `sync_events_log` → archive ke separate table tiap bulan

### 10.4 Materialized views (via cache table)

Heavy reports yang diakses berulang:
- `mv_dashboard_kpi` (KPI Bank Loan) — refresh tiap jam via Horizon
- `mv_aging_ar` — refresh harian
- `mv_aging_ap` — refresh harian
- `mv_collection_summary` — refresh tiap 15 menit

---

## 11. Migration Strategy dari Legacy

### 11.1 SQL Server → MySQL data type mapping

| SQL Server | MySQL | Catatan |
| --- | --- | --- |
| `INT IDENTITY` | `BIGINT UNSIGNED AUTO_INCREMENT` | Promote ke BIGINT untuk future-proof |
| `NVARCHAR(N)` | `VARCHAR(N)` charset utf8mb4 | |
| `NVARCHAR(MAX)` | `TEXT` / `LONGTEXT` | Sesuai expected size |
| `DATETIME` / `DATETIME2` | `DATETIME(6)` | Microsecond precision |
| `DATETIMEOFFSET` | `TIMESTAMP` UTC + app-side TZ | |
| `BIT` | `BOOLEAN` (TINYINT(1)) | |
| `DECIMAL(18, 2)` | `DECIMAL(18, 2)` | identical |
| `UNIQUEIDENTIFIER` | `CHAR(36)` UUID | atau `BINARY(16)` jika perlu kompak |
| `MONEY` | `DECIMAL(19, 4)` | |
| `IMAGE` / `VARBINARY` | `BLOB` / `LONGBLOB` | Better: pindah file ke filesystem/S3 |
| `XML` | `JSON` | parse + transform |

### 11.2 Tools migrasi

1. **AWS SCT (Schema Conversion Tool)** atau **MySQL Workbench Migration Wizard** untuk schema baseline
2. **dbeaver** atau **HeidiSQL** untuk data export per tabel
3. Custom **Laravel artisan command** `php artisan migrate:legacy --table=fo_customers --source-db=legacy_marketing` untuk ETL dengan transformasi (status code mapping, etc.)
4. **Reconciliation script**: hash row count + sum amount per tabel — must match 100% sebelum cutover

### 11.3 Encoding & collation issues

- Legacy SQL Server: `SQL_Latin1_General_CP1_CI_AS` atau `Indonesian_CI_AS`
- Target MySQL: `utf8mb4_unicode_ci`
- Risiko: karakter Indonesia khusus (é, ñ — jarang) bisa rusak. **Wajib** test ekstensif.

---

## 12. Acceptance Criteria untuk Schema

Schema target dianggap **production-ready** jika:

1. ✅ Semua tabel ada migration file (Laravel migrations) + rollback tested
2. ✅ Semua FK constraint aktif (no orphan data)
3. ✅ Semua unique constraint tested (no duplicate nomor dokumen possible)
4. ✅ Index coverage: query analyzer (`EXPLAIN`) untuk top-20 query show `Using index` atau `Using where; Using index`
5. ✅ Soft delete + audit columns konsisten di seluruh tabel transaksi
6. ✅ Seed data master complete (banks, projects, companies, default chart of accounts)
7. ✅ Migrasi data dari legacy 100% (verified by reconciliation script)
8. ✅ Backup + restore drill dari `mysqldump` sukses
9. ✅ Performance baseline: query test dengan dataset realistis (100k customers, 50k kontrak, 500k tts) <500ms P95
10. ✅ Documentation lengkap (table dictionary, FK map, status enum reference)
