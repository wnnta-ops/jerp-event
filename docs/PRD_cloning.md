# PRD — J-ERP (eProperty Clone) ke Laravel 12 (Unified App)

**Product Requirements Document — versi target untuk membangun ulang sistem.**
Status: spesifikasi target. Sumber: [PRD.md](PRD.md) (existing system), [ERD.md](ERD.md) (existing schema).
Companion: [ERD_cloning.md](ERD_cloning.md), [claude_cloning.md](claude_cloning.md).

---

## 1. Visi & Perubahan Kunci

**J-ERP** (clone dari eProperty legacy) adalah **satu aplikasi monolithic Laravel 12** yang menggantikan suite multi-app legacy (BO + FO multi-sub-app + KPR API + TuxArch) menjadi **single deployment, single database MySQL**, dengan empat menu utama:

```
┌──────────────────────────────────────────────────────────────┐
│                         J-ERP (Laravel 12)                    │
│                                                               │
│  ┌──────────┐  ┌──────────┐  ┌────────────┐  ┌─────────────┐ │
│  │   FO     │  │   BO     │  │  BankLoan  │  │   Backup    │ │
│  │ (Front   │  │ (Back    │  │   (KPR)    │  │  (TuxArch)  │ │
│  │  Office) │  │  Office) │  │            │  │             │ │
│  └──────────┘  └──────────┘  └────────────┘  └─────────────┘ │
│                                                               │
│            Shared: Auth · Users · Roles · Master              │
└──────────────────────────────────────────────────────────────┘
            │            │            │            │
            └────────────┴────┬───────┴────────────┘
                              ▼
                  ┌────────────────────┐
                  │   MySQL 8 (one DB) │
                  │   jerp             │
                  └────────────────────┘
```

### Perubahan kunci dari sistem lama

| Aspek | Lama | Baru (Target) |
| --- | --- | --- |
| **Jumlah aplikasi** | 4 aplikasi (BO + FO multi-sub-app + KPR API + TuxArch) | **1 aplikasi Laravel 12** |
| **Sub-aplikasi FO** | 13 sub-app IIS terpisah (`security`, `marketingjual`, `financear`, `collection`, `kpa`, `legal`, `launching`, `nup`, `approval`, `adminjual`, `komisi`, `dashboard`, `settings`) | **Satu modul "FO"** dengan namespace per area (Domain-Driven Design) |
| **Database** | 17 database SQL Server (`IPRO_DL_*_011MUP_*`, `MUP_BankLoan*`, `MUP_Agenku`) | **1 database MySQL** dengan **prefix tabel per modul** (`fo_`, `bo_`, `bankloan_`, `backup_`) |
| **Database engine** | SQL Server 2017/2019 | **MySQL 8.0+** (atau MariaDB 10.11+) |
| **Framework backend** | ASP.NET WebForms 3.5/4.x + 4.8 + .NET 7 FastEndpoints | **Laravel 12 (PHP 8.3+)** |
| **Frontend** | WebForms postback + jQuery + Bootstrap klasik | **Livewire 3 + Alpine.js + Tailwind 4** (atau Filament 3.x untuk admin-heavy modul) |
| **Auth** | Forms auth per app + JWT (KPR only) | **Laravel Sanctum** (web session + token API, SSO universal) |
| **RBAC** | Custom tabel per app | **Spatie Permission** + custom layer untuk multi-company |
| **Background job** | Hangfire (KPR) + VBScript polling URL (FO) | **Laravel Horizon** (Redis-backed queue) |
| **Cache** | Redis (KPR) + InProc (FO/BO) | **Redis** untuk cache, session, queue, locks |
| **Realtime** | SignalR (FO `launching/UnitPetaLive.aspx`) | **Laravel Reverb** (WebSocket native) |
| **PDF** | NReco.PdfGenerator + wkhtmltopdf (KPR), custom .aspx (FO/BO) | **Spatie Browsershot** (Puppeteer) atau **barryvdh/laravel-dompdf** |
| **Excel** | ClosedXML, EPPlus | **maatwebsite/excel** (Laravel Excel) |
| **Email** | FluentEmail + MailKit | **Laravel Mail** (SMTP / SES / Mailgun) + **Laravel Notification** |
| **Logging** | Serilog → File + MSSqlServer | **Monolog + Telescope** (dev) + dedicated `audit_logs` table |
| **Backup engine** | TuxArch (custom + Windows Service) | **spatie/laravel-backup** + custom controller untuk UI TuxArch-style |
| **Scheduler** | TuxBackupScheduler Windows Service + VBScript | **Laravel Scheduler** (`php artisan schedule:run` via cron) |
| **Payment** | Xendit via `.asmx` + `.vbs` | **Laravel Xendit SDK** (`xendit/xendit-php`) atau HTTP client |
| **Storage** | Local filesystem (path Windows) | **Laravel Filesystem** (local + S3/MinIO driver) |
| **Search** | SQL LIKE | **Laravel Scout** + Meilisearch (untuk dropdown unit, customer, kontrak yang banyak) |
| **API surface** | `.asmx` SOAP + REST FastEndpoints | **Laravel API Resources** (versioned REST `/api/v1`) + opsional GraphQL |
| **Multi-tenant** | Hard-coded di nama DB (`_011MUP_`) | **Soft multi-tenant** via `tenant_id` di setiap tabel (siap SaaS) |
| **Internationalization** | `id-ID` saja, hard-coded | **Laravel Localization** (`lang/id/`, `lang/en/`) — UI utama Indonesia, error/dev English |

### Yang TIDAK berubah

- **Bahasa UI**: tetap Indonesia (istilah finansial Indonesia: TTS, BKM, PPJB, AJB, NUP, dst.)
- **Format dokumen cetak**: tetap byte-compatible dengan kuitansi/voucher existing
- **Kode dokumen / numerator**: tetap `KPR-`, `RA-`, `PR-`, `RR-`, dst.
- **Logika bisnis**: workflow pengajuan KPR, posting jurnal FO→BO, perhitungan komisi, retensi — **identik**, hanya implementasi yang baru
- **Integrasi**: Xendit, reCAPTCHA, bank file format (BCA/BNI/BRI/Mandiri), e-Faktur — tetap

---

## 2. Stack Teknologi Target

### 2.1 Core stack

| Lapisan | Pilihan | Versi minimum | Alasan |
| --- | --- | --- | --- |
| **Bahasa** | PHP | 8.3 | Required oleh Laravel 12 |
| **Framework** | Laravel | 12.x | LTS terbaru, support PHP 8.3 |
| **Database** | MySQL | 8.0+ (rekomendasi 8.4 LTS) atau MariaDB 10.11+ | JSON support, window functions, CTE |
| **Cache / Queue / Session** | Redis | 7.x | Persistent option, Laravel Horizon ready |
| **Web server** | Nginx + PHP-FPM | Nginx 1.24, PHP-FPM 8.3 | Standard production |
| **OS deployment** | Ubuntu Server (di dalam container) | 24.04 LTS base image | Native PHP-FPM, Redis, MySQL, supervisord |
| **Container** | Docker + Docker Compose | Docker 26+, Compose v2 | **Production-first**: SEMUA service (app, nginx, mysql, redis, horizon, scheduler, reverb, meilisearch) jalan sebagai container. Compose untuk single-host; K8s/Swarm untuk scaling. Lihat §13. |

### 2.2 Laravel ecosystem packages

| Kebutuhan | Package | Catatan |
| --- | --- | --- |
| Authentication | `laravel/sanctum` | Web session + API token; SSO antar menu FO/BO/BankLoan/Backup |
| RBAC (per-modul, per-company) | `spatie/laravel-permission` | Plus custom guard untuk multi-company |
| Admin scaffolding (BO + Backup) | `filament/filament` 3.x | Resource-based admin untuk modul BO yang form-heavy |
| UI interaktif (FO + Launching realtime) | `livewire/livewire` 3.x + Alpine.js | Untuk halaman FO yang complex (Reservasi, Unit picker, Customer regis) |
| Asset bundling | Vite + Tailwind CSS 4 | Default Laravel 12 |
| Background jobs | `laravel/horizon` | Pengganti Hangfire; dashboard di `/horizon` |
| Realtime (Launching live unit) | `laravel/reverb` | Native WebSocket; broadcast `UnitStatusChanged` event |
| PDF generation | `spatie/browsershot` (Puppeteer) | Lebih fleksibel untuk template komplex (PPJB, AJB, TTS). Alternatif: `barryvdh/laravel-dompdf` untuk yang simpel |
| Excel | `maatwebsite/excel` | Export laporan, import jadwal tagihan, VA upload/download per bank |
| Audit log | `spatie/laravel-activitylog` | Pengganti BO `Security/Log*.aspx` |
| Database backup (TuxArch replacement) | `spatie/laravel-backup` | + custom UI Blade/Filament untuk dashboard TuxArch |
| Settings (per-tenant config) | `spatie/laravel-settings` | Pengganti `Security/Setup*.aspx` |
| Media library (foto, dokumen) | `spatie/laravel-medialibrary` | Pengganti `PathFile`, `PathImage`, `PathAttachment` legacy |
| Healthcheck | `spatie/laravel-health` | Untuk monitoring (uptime, queue, scheduler, DB) |
| Search (autocomplete heavy) | `laravel/scout` + Meilisearch | Dropdown Customer/Unit/Kontrak |
| Notifications | Laravel Notifications + `laravel-notification-channels/webpush` | In-app, email, push, WhatsApp (channel custom) |
| Localization | Laravel built-in `lang/` | id (primary), en (fallback) |
| API documentation | `dedoc/scramble` atau `knuckleswtf/scribe` | OpenAPI auto-generated |
| Testing | Pest 3.x + Laravel Dusk | Unit + Feature + Browser |
| Static analysis | Larastan (PHPStan level 8) + Laravel Pint | Code quality enforced di CI |
| Dev tools | Laravel Telescope, Laravel Debugbar | Dev environment saja |
| Excel for KPR reminders | Inherit `maatwebsite/excel` | Reminder-BI-Checking.xlsx, Reminder-Pengajuan-Retensi.xlsx |
| Image processing | `intervention/image` 3.x | Resize foto unit, foto customer, watermark |
| QR / Barcode | `simplesoftwareio/simple-qrcode` | Untuk slip TTS / NUP / PPJB |
| HTTP client | Laravel HTTP Facade (Guzzle) | Untuk Xendit, sync ke bank, dst. |
| Currency / number formatting | `moneyphp/money` atau native | Format Rupiah (id-ID) di seluruh app |

### 2.3 Frontend & Design System

**Default stack**: Livewire 3 + Alpine.js + Tailwind CSS 4 (server-rendered dengan reactive partials). Cocok untuk:
- Form-heavy pages (PPJB, AJB, Kontrak, TTS Registrasi, Reservasi)
- Tabel dengan inline filter
- Approval workflow

**Filament 3** untuk modul **BO (accounting)**, **Backup (TuxArch)**, dan **System** — Resource-based admin generator menghemat kode boilerplate CRUD. Filament 3 sudah ship modern: dark mode native, mobile-friendly, color schemes konfigurable.

**Vue 3 / React** (opsional, hanya jika butuh): untuk **Launching Live Map** (real-time site plan) dan **Dashboard Analytics** yang heavy interaktif. Default dulu Livewire + Reverb (cukup).

#### 2.3.1 Prinsip desain (WAJIB)

J-ERP harus terlihat dan terasa **modern**, **responsive**, dan **konsisten** di seluruh modul:

| Prinsip | Implementasi |
| --- | --- |
| **Mobile-first** | Semua halaman wajib usable di viewport ≥ 360px. Sidebar collapse otomatis di `<lg` (1024px). Tabel besar pakai pattern: horizontal scroll + sticky kolom pertama, atau **card view** di mobile. |
| **Responsive breakpoints** | Tailwind default: `sm` 640 / `md` 768 / `lg` 1024 / `xl` 1280 / `2xl` 1536. Form: 1 kolom <md, 2 kolom ≥md, 3 kolom ≥xl. |
| **Dark mode** | Wajib di-support semua menu. Strategy: `class="dark"` di `<html>`, toggle di topbar + auto follow `prefers-color-scheme`. Filament panels (BO/Backup/System) ikut. |
| **Density** | Default `comfortable`. Setting per-user `compact` untuk power-user finance (tabel jurnal 15+ kolom). |
| **Touch target** | Minimum 44×44 px untuk action button di mobile (sesuai WCAG 2.5.5). |
| **Aksesibilitas** | WCAG 2.1 AA: contrast ≥ 4.5:1 text, keyboard nav semua action, `aria-*` labels untuk icon-only button, focus ring visible. |
| **Performance budget** | First Contentful Paint <1.5s di 4G, Largest Contentful Paint <2.5s, JS bundle initial <200 KB gzipped. |

#### 2.3.2 Design tokens (Tailwind 4 `@theme`)

Defined di `resources/css/tokens.css` — single source of truth:

```css
@theme {
  /* Brand */
  --color-brand-50:  oklch(0.97 0.02 250);
  --color-brand-500: oklch(0.55 0.18 250);   /* primary J-ERP blue */
  --color-brand-700: oklch(0.42 0.18 250);
  --color-brand-900: oklch(0.25 0.10 250);

  /* Semantic */
  --color-success: oklch(0.65 0.18 145);
  --color-warning: oklch(0.78 0.18 75);
  --color-danger:  oklch(0.60 0.22 25);
  --color-info:    oklch(0.65 0.15 220);

  /* Surface (dark-mode aware via .dark variant) */
  --color-surface:        oklch(0.99 0 0);   /* light */
  --color-surface-muted:  oklch(0.96 0 0);
  --color-surface-border: oklch(0.90 0 0);

  /* Typography */
  --font-sans: "Inter Variable", "Plus Jakarta Sans", system-ui, sans-serif;
  --font-mono: "JetBrains Mono", ui-monospace, monospace;
  --font-numeric: "Inter Variable", tabular-nums;  /* uang & angka di tabel */

  /* Spacing scale, radius, shadow, motion durations — defined here */
  --radius-card: 0.75rem;       /* 12px rounded-xl */
  --shadow-elev-1: 0 1px 2px 0 oklch(0 0 0 / 0.04);
  --shadow-elev-2: 0 4px 12px -2px oklch(0 0 0 / 0.08);
  --motion-fast: 120ms;
  --motion-base: 200ms;
}
```

**Aturan**: SEMUA color / spacing / radius di Blade & Livewire WAJIB pakai token, **JANGAN** hex literal di markup.

#### 2.3.3 Komponen library

- **Base**: Tailwind 4 utility-first + custom Blade components di `resources/views/components/`:
  - `<x-button variant="primary|secondary|danger|ghost" size="sm|md|lg">`
  - `<x-card>`, `<x-card.header>`, `<x-card.body>`, `<x-card.footer>`
  - `<x-form.input>`, `<x-form.select>`, `<x-form.textarea>`, `<x-form.money>` (auto-format Rupiah), `<x-form.date>` (datepicker Indonesia)
  - `<x-table>`, `<x-table.row>`, `<x-table.empty-state>`
  - `<x-badge variant="...">`, `<x-status-pill>` (status code Indonesia: BOOKING / LUNAS / BATAL dst.)
  - `<x-modal>`, `<x-drawer>`, `<x-tabs>`, `<x-stepper>` (untuk wizard PPJB / KPR)
- **Filament 3** mengisi admin (BO / Backup / System) tanpa custom — cukup tema disesuaikan dengan token brand di `app/Providers/Filament/*Provider.php` (`->colors([...])` + `->darkMode()`).
- **Icon set**: Heroicons 2 (sudah default Laravel) atau Lucide.

#### 2.3.4 Layout pattern

- **App shell** (semua menu auth):
  - Topbar: logo J-ERP + breadcrumb + search global (Meilisearch-backed `⌘K`) + notif bell + theme toggle + user menu.
  - Sidebar kiri: nav 4 menu utama, collapsible. Persist state di localStorage.
  - Main content: max-width `max-w-screen-2xl`, padding responsive.
- **Empty state, loading state, error state** wajib di-design — gunakan komponen `<x-empty-state>`, `<x-skeleton>`, `<x-error-state>`. Jangan tampilkan tabel kosong tanpa CTA.
- **Toast notification**: pakai Livewire flash + `<x-toast>` (auto-dismiss 5s, dismissible).
- **Print mode**: `@media print` style untuk semua dokumen cetak (PPJB, kuitansi, voucher) — hide sidebar/topbar, ukuran A4/A5.

#### 2.3.5 Indonesian locale di UI

- Format angka: `1.234.567,89` (id-ID). Helper `formatRupiah($value)` → `Rp 1.234.567`.
- Format tanggal: `28 Mei 2026` (default), `28/05/2026` (compact), `Kamis, 28 Mei 2026` (long form di dokumen). Pakai Carbon macro `->translatedFormat()` dengan locale `id`.
- Hari kerja Indonesia di datepicker (default), holiday Indonesia opsional via library.

### 2.4 Tools development

- **IDE**: VS Code + Laravel Pint + Intelephense (atau PHPStorm)
- **DB GUI**: TablePlus / DBeaver / MySQL Workbench
- **API testing**: Bruno / Postman / Insomnia
- **Container**: Laravel Sail (`./vendor/bin/sail`) untuk dev parity
- **CI/CD**: GitHub Actions atau GitLab CI → Deployer / Forge / Envoyer / Ploi

---

## 3. Struktur Aplikasi & Navigasi

### 3.1 Top-level navigation

Setelah login, user landing ke **Dashboard** sesuai role default-nya. Sidebar utama:

```
┌─────────────────────────────┐
│  J-ERP                      │
├─────────────────────────────┤
│  🏠 Dashboard               │
│                             │
│  📊 FO  (Front Office)      │  ◄── operasional customer-facing
│      ├ Marketing & Sales    │
│      ├ Reservasi & Kontrak  │
│      ├ Finance AR           │
│      ├ Collection           │
│      ├ Legal                │
│      ├ Launching & NUP      │
│      ├ Komisi               │
│      ├ Approval             │
│      └ Master (Adminjual)   │
│                             │
│  📒 BO  (Back Office)       │  ◄── accounting & ERP internal
│      ├ General Ledger       │
│      ├ Accounts Receivable  │
│      ├ Accounts Payable     │
│      ├ Cash & Bank          │
│      ├ Inventory            │
│      ├ HR                   │
│      ├ FO-BO Bridge         │
│      └ Setup & Numerator    │
│                             │
│  🏦 BankLoan (KPR)          │  ◄── proses kredit bank
│      ├ Customer KPR         │
│      ├ Kontrak KPR          │
│      ├ Pengajuan Bank       │
│      ├ BI Checking          │
│      ├ Realisasi (RA)       │
│      ├ Retensi (PR / RR)    │
│      ├ MoM Bank             │
│      └ Dashboard KPI        │
│                             │
│  💾 Backup (TuxArch)        │  ◄── DB backup management
│      ├ Dashboard            │
│      ├ Kelola Backup        │
│      ├ Riwayat Log          │
│      ├ Jadwal Backup        │
│      ├ Laporan Bulanan      │
│      └ Konfigurasi          │
│                             │
│  ⚙ Sistem                   │  ◄── shared global
│      ├ Pengguna             │
│      ├ Role & Permission    │
│      ├ Master Project       │
│      ├ Master Bank          │
│      ├ Master Company       │
│      └ Settings             │
└─────────────────────────────┘
```

Menu **NUP Public** (customer registration) adalah **route terpisah tanpa autentikasi** (`/nup/daftar`) — tidak masuk sidebar admin, tetap satu aplikasi.

### 3.2 Permission model

```
Permission hierarchy:
  module.area.action

Contoh:
  fo.marketingjual.kontrak.create
  fo.marketingjual.kontrak.approve
  fo.financear.tts.void
  bo.gl.jurnal.post
  bankloan.contract.bi_checking.upload
  backup.schedule.edit
  system.users.manage
```

Role default (di-seed):
- `super_admin` — semua permissions
- `direktur` — read-all + approve high-value
- `finance_manager` — bo.* + fo.financear.* + fo.collection.* (manage)
- `finance_staff` — fo.financear.* (create/edit, no void)
- `sales_manager` — fo.marketingjual.* + fo.komisi.* + fo.approval.*
- `sales_staff` — fo.marketingjual.* (limited)
- `legal_staff` — fo.legal.* + fo.marketingjual.kontrak.view
- `kpr_officer` — bankloan.* (full)
- `collector` — fo.collection.* (full)
- `kasir_launching` — fo.launching.* + fo.nup.* (event-only)
- `it_admin` — backup.* + system.*
- `auditor` — read-all (no write)

Scope tambahan (di luar permission): `company_id`, `project_id` — user dibatasi hanya melihat data company/project tertentu.

### 3.3 Single Sign-On antar menu

User login satu kali → session valid untuk semua menu (FO/BO/BankLoan/Backup). Tidak ada lagi 4 login terpisah seperti legacy.

---

## 4. Modul Fungsional (Spec Singkat)

> Detail logika bisnis (workflow, status machine, dokumen cetak) **identik** dengan [PRD.md](PRD.md) — bagian di bawah ini hanya menjelaskan **pemetaan ke struktur Laravel 12 + nama route/controller**.

### 4.1 Modul `FO` (Front Office)

Namespace: `App\Modules\FO`
Route prefix: `/fo`
Middleware: `auth`, `can:fo.access`

Sub-area (di-organize sebagai sub-namespace, **bukan sub-app**):

| Sub-area | Namespace | Mengganti FO sub-app legacy |
| --- | --- | --- |
| Marketing & Sales | `App\Modules\FO\MarketingSales` | `marketingjual/` (drop `marketingjualold/`) |
| Finance AR | `App\Modules\FO\FinanceAR` | `financear/` |
| Collection | `App\Modules\FO\Collection` | `collection/` |
| Legal | `App\Modules\FO\Legal` | `legal/` |
| Launching & NUP | `App\Modules\FO\Launching` | `launching/` + `nup/` (digabung) |
| KPA Customer-View | `App\Modules\FO\KpaView` | `kpa/` (sebagian besar pindah ke `BankLoan`) |
| Komisi | `App\Modules\FO\Komisi` | `komisi/` |
| Approval | `App\Modules\FO\Approval` | `approval/` |
| Master Adminjual | `App\Modules\FO\Adminjual` | `adminjual/` |
| Dashboard FO | `App\Modules\FO\Dashboard` | `dashboard/` |
| Settings FO | `App\Modules\FO\Settings` | `settings/` |

Setiap sub-area memiliki struktur:
```
app/Modules/FO/MarketingSales/
├── Controllers/         (atau Livewire/ jika pakai Livewire)
├── Models/              (Customer, Unit, Reservasi, Kontrak, Skema, ...)
├── Requests/            (FormRequest validation)
├── Services/            (business logic: ReservasiService, KontrakService)
├── Events/              (KontrakCreated, KontrakApproved)
├── Listeners/
├── Policies/
├── DataTransferObjects/
└── routes.php
```

**NUP Public registration**: `App\Modules\FO\Launching\Public` dengan route `/nup/daftar` (unauthenticated), guard `web` tanpa middleware `auth`, plus rate-limit + reCAPTCHA v3.

**Launching realtime** (peta unit live): pakai **Laravel Reverb** + Livewire untuk update real-time status unit. Event: `App\Events\UnitStatusChanged` di-broadcast ke channel `project.{projectId}.units`.

### 4.2 Modul `BO` (Back Office)

Namespace: `App\Modules\BO`
Route prefix: `/bo`
**Disarankan pakai Filament Panel** (`/bo`) karena modul BO 80% form CRUD akuntansi.

| Sub-area | Filament Resource / Custom |
| --- | --- |
| Chart of Account | `AccountResource` |
| General Ledger (Jurnal) | `JurnalResource` + custom posting workflow |
| Accounts Receivable | `InvoiceArResource`, `BpdResource`, ... |
| Accounts Payable | `InvoiceApResource`, `KuitansiResource`, `TtgResource`, ... |
| Cash & Bank | `BkmResource`, `BkkResource`, `BgmResource`, ... |
| Inventory | `MasterStokResource`, `FpbResource`, `PrResource`, `BkbResource`, ... |
| HR | `KaryawanResource`, `BrqResource`, `HrDeptResource` |
| FO-BO Bridge | Custom page untuk monitoring posting otomatis FO→BO |
| Setup & Numerator | `NumeratorResource`, `CompanyResource`, `CostCenterResource` |

Custom selain CRUD:
- **Posting Jurnal Otomatis** dari FO → BO: pakai **Event/Listener** (e.g. `TtsPosted` listener → `PostJurnalFOToBO`).
- **Closing periode**: custom Filament page dengan workflow approval.
- **Print voucher** (BKM/BKK/BGM/BGK/Invoice/Kuitansi/TTG/Memorial): PDF via Browsershot, template Blade.

### 4.3 Modul `BankLoan` (KPR API ditarik jadi monolith)

Namespace: `App\Modules\BankLoan`
Route prefix: `/bankloan`
**Tetap expose API** di `/api/v1/bankloan/*` untuk konsumsi mobile / pihak luar.

Sub-area:

| Sub-area | Domain |
| --- | --- |
| Customer KPR | Customer (mirror dari FO, plus data KPR-specific: Occupation, Income) |
| Kontrak KPR | Contract, ContractActiveStatus, ContractBuybackStatus, PreInterview |
| Pengajuan Bank | ContractBank, ContractBankRequirement (upload berkas), ContractBankInterview |
| SP3K & Akad | ContractBankSP3K, ContractBankAgreement |
| BI Checking | BIChecking + BICheckingFile |
| Realisasi Akad (RA) | AgreementRealization + ExcessRealization |
| Retensi (PR / RR) | RetentionSubmission, RetentionRealization, RetentionCategory, BankRetentionScheme |
| Buyback | Contract Buyback flow |
| MoM Bank | MinutesOfMeeting (per Project & Bank) |
| Reminder Job | Hangfire jobs → Laravel Horizon jobs: `SendBIReminderJob`, `SendRetensiReminderJob` (mengirim Excel via email) |
| Dashboard KPI | `SpDashboardKpi` di-port ke Eloquent + cached view |
| Sync from FO | Listener: `KontrakCreated` (di FO) → `SyncContractToBankLoan` |
| Template dokumen | `DocumentTemplate` (RazorLight → Blade) untuk render PDF kontrak/akad |

**Penting**: KPR API existing punya **103 migration EF Core** di SQL Server. Saat clone:
1. Migrasi schema → Laravel migrations (`database/migrations/2025_xx_xx_<module>_<step>.php`)
2. Stored procedure `SP_FOtoStandAlone_*` **TIDAK di-port** — diganti dengan event-driven sync via Laravel Events (`KontrakCreated` event → handler `SyncToBankLoan`)
3. Stored procedure `SP_DashboardKpi` → di-port ke Eloquent query + cached (Redis 1 jam TTL)
4. Stored procedure `FN_ToLongDateString` → Blade helper / Carbon macro

### 4.4 Modul `Backup` (TuxArch replacement)

Namespace: `App\Modules\Backup`
Route prefix: `/backup`
**Filament panel** untuk UI.

Sub-area:

| Sub-area | Implementasi |
| --- | --- |
| Dashboard | Total DB monitored, sukses/gagal hari ini, storage size, retention status |
| Kelola Backup | List backup history + run on-demand (job ke queue) |
| Riwayat Log | Filter per database, status, tanggal — query dari `backup_logs` table |
| Jadwal Backup | UI untuk edit `backup_schedules` (di-trigger oleh Laravel Scheduler) |
| Laporan Bulanan | Generate PDF + email + Trello card (job harian tgl 1) |
| Konfigurasi | Edit setting backup (path, retention, compression, SMTP, Trello, alert recipients) |

**Engine backup**:
- Base: `spatie/laravel-backup` (gzipped MySQL dump + filesystem backup)
- Custom: support backup ke S3/MinIO, retention configurable per-database, hash check
- Scheduling: `app/Console/Kernel.php` → `$schedule->job(new BackupDatabase('jerp'))->dailyAt('00:30')`
- Notifikasi failure: 3 hari berturut-turut → email ke `AlertRecipients` + Trello card via webhook

**Beda dari TuxArch existing**:
- Tidak lagi Windows Service — sepenuhnya di-handle Laravel Scheduler (cron `* * * * * php artisan schedule:run`)
- Tidak lagi multi-DB (cukup backup `jerp` MySQL DB tunggal + file storage)
- Tetap kompatibel: bisa export ke format `.sql.gz` yang restorable via `mysql < file.sql.gz`

### 4.5 Modul `System` (shared)

Namespace: `App\Modules\System`
Route prefix: `/system`
**Filament panel**.

| Sub-area | Domain |
| --- | --- |
| Users | CRUD user dengan multi-role, foto profil, password reset |
| Roles & Permissions | Spatie permission UI |
| Master Project | Project (multi-company, per-tenant) |
| Master Company | Company (developer / PT entity) |
| Master Bank | Bank (untuk KPR & rekening perusahaan) |
| Master Cost Center | Departemen / Cost Center |
| Numerator | Setup nomor dokumen per jenis (KPR-, TTS-, BKM-, dst.) |
| Settings | Global config (per-tenant: SMTP, Xendit key, Trello, reCAPTCHA, file storage) |
| Audit Log | Activity log (via Spatie ActivityLog) |
| Backup (mirror navigasi) | Quick access ke modul Backup |

---

## 5. Database

Lihat **[ERD_cloning.md](ERD_cloning.md)** untuk detail schema. Ringkasan keputusan:

### 5.1 Single database, prefix per modul

```
Database: jerp (MySQL 8)

Table prefixes:
  (tanpa prefix)  — shared: users, roles, permissions, projects, companies, banks, ...
  fo_             — FO module (customers, units, reservasi, kontrak, tts, bkm, ...)
  bo_             — BO module (jurnal, account, invoice_ap, kuitansi, ...)
  bankloan_       — BankLoan (contracts, contract_banks, bi_checkings, ra, pr, rr, ...)
  backup_         — Backup (schedules, logs, configs)
  audit_          — Audit logs (Spatie ActivityLog default `activity_log`)
```

Alasan prefix bukan database terpisah:
- Single backup file (1 dump = 1 sistem lengkap)
- Single migration runner
- Cross-module JOIN langsung (tidak perlu federation)
- Lebih mudah untuk multi-tenant masa depan (cukup tambah `tenant_id` di setiap tabel)

### 5.2 Multi-tenant ready

Setiap tabel data (kecuali yang truly global seperti `users`, `roles`, `permissions`, `migrations`) punya kolom `tenant_id` (nullable di v1, di-default 1 untuk MUP). Saat SaaS scaling, switch ke single-DB multi-tenant dengan `stancl/tenancy` atau hand-rolled scope.

### 5.3 Engine & charset

- Engine: **InnoDB** (semua tabel)
- Charset: **utf8mb4** + collation **utf8mb4_unicode_ci**
- Time: semua `TIMESTAMP` UTC, presentation di-convert ke `Asia/Jakarta` (config `app.timezone`)

### 5.4 Soft delete & audit

- `softDeletes()` di semua tabel transaksi
- `created_at`, `updated_at` standard Laravel
- `created_by`, `updated_by`, `deleted_by` (FK ke `users`) ditambahkan di trait `Auditable`
- Activity log via `spatie/laravel-activitylog` untuk transaksi sensitif (Jurnal, TTS, BKM, Kontrak, KPR ContractBank, RA, RR)

---

## 6. Integrasi Eksternal

| Layanan | Implementasi Laravel |
| --- | --- |
| **Xendit** | Service class `App\Services\Payment\XenditService` (HTTP client / SDK). Webhook endpoint `/webhooks/xendit` (verify signature). Auto-create TTS dari callback. |
| **Google reCAPTCHA v3** | Middleware `App\Http\Middleware\VerifyRecaptcha` di route `/nup/daftar` |
| **SMTP** | Laravel Mail (`MAIL_*` env), default Gmail SMTP relay (configurable) |
| **Trello** | `App\Services\Notification\TrelloService` untuk monthly report card + backup alert |
| **WhatsApp Blast** | Provider TBD (Wablas/Twilio/Vonage) → channel custom `WhatsAppChannel` untuk Laravel Notification |
| **wkhtmltopdf → Puppeteer** | `spatie/browsershot` (Node + Puppeteer) — Browsershot lebih modern + bagus untuk CSS3. Alternatif fallback `barryvdh/laravel-dompdf` |
| **Bank file format** | `App\Services\Bank\{BCA,BNI,BRI,Mandiri}\VAImporter` & `VAExporter` — pertahankan format file existing (operator sudah terbiasa) |
| **DJP e-Faktur** | `App\Services\Tax\EFakturExporter` — generate CSV format DJP. Optional: Host-to-Host DJP API |
| **BI Checking** | Manual upload (operator) → file disimpan ke S3 → record di `bi_checking_files` |
| **GitHub auto-update (TuxArch)** | Removed — replace dengan standard Laravel deployment (Deployer / Forge / Envoyer) |

---

## 7. Persyaratan Non-Fungsional

### 7.1 Performa

| Metrik | Target |
| --- | --- |
| Dashboard load | <800ms (P95) |
| Form submit (CRUD) | <500ms (P95) |
| Search autocomplete (customer/unit) | <200ms (P95), backed by Meilisearch |
| Launching live unit update | <500ms latency (WebSocket via Reverb) |
| Backup full DB | <30 menit untuk DB hingga 5 GB |
| Concurrent users | 100 active sessions (target initial), scalable to 500 |
| Report generation (KPI, aging) | <10 detik untuk 100k rows |

Strategi:
- Query cache via Redis (5–60 menit TTL untuk dashboard & dropdown master)
- Eager loading + `select` columns spesifik (jangan `SELECT *`)
- Index sesuai [ERD_cloning.md §10](ERD_cloning.md)
- Queue untuk operasi berat: PDF generation batch, email blast, posting jurnal massal, backup
- Horizon supervisor untuk auto-scale worker

### 7.2 Keamanan

| Kontrol | Implementasi |
| --- | --- |
| Password hashing | Bcrypt (Laravel default) cost 12+ |
| Session | Redis-backed, secure cookies, `same_site=lax`, regenerate on login |
| CSRF | Laravel default middleware |
| API auth | Sanctum personal access token (revocable) + IP whitelist untuk admin API |
| Rate limiting | Per-route via `throttle` middleware: 60 req/min default, 5 req/min untuk login/register |
| Login lock | 5 failed attempts → lock 30 menit (mengikuti existing KPR API) |
| Password policy | Min 12 char, complexity rules, history 5, max age 90 hari (configurable per-tenant) |
| 2FA | TOTP via `pragmarx/google2fa-laravel` untuk role admin/finance |
| File upload | Validate MIME + size, antivirus scan (opsional ClamAV), store di S3 (not webroot) |
| Audit log | All write actions di-log; immutable (`audit_logs` partition per bulan) |
| SQL injection | Eloquent ORM + parameter binding only — no raw queries kecuali via `DB::statement` dengan binding |
| XSS | Blade auto-escape (`{{ }}`), `htmlspecialchars` |
| Secrets | `.env` di luar webroot, prod menggunakan secret manager (AWS Secrets Manager / Vault) |
| HTTPS | Wajib di production (Let's Encrypt + HSTS) |
| IP whitelist | Untuk admin panel (BO, Backup, System) — configurable per-tenant |
| reCAPTCHA | NUP public, login, password reset |

### 7.3 Observability

- **Logs**: Monolog → stdout (Docker) + file `storage/logs/laravel.log` + remote (Loki/Seq via Filebeat)
- **Metrics**: Prometheus exporter (custom + Horizon metrics) + Grafana dashboard
- **Tracing**: opsional OpenTelemetry SDK
- **Error tracking**: Sentry / Bugsnag (free tier untuk start)
- **Uptime**: Healthcheck endpoint `/up` + `/health` (via Spatie Health)
- **Audit**: Spatie ActivityLog ke MySQL `activity_log` table

### 7.4 Reliability

- Backup harian (terjadwal 00:30 dan opsional 12:00) → S3 + local
- Retention 365 hari (configurable, recommended 7 tahun untuk audit pajak Indonesia)
- Database replication (async slave) untuk read-heavy reports
- Queue worker supervised (supervisord / systemd) + Horizon auto-restart
- Maintenance mode aware (`php artisan down`) dengan allowed IP whitelist

### 7.5 Compliance

- **Audit trail** untuk semua perubahan transaksi finansial (UU PPN, UU PPh, OJK)
- **Retention 10 tahun** untuk dokumen pajak (PPN/PPh) — backup wajib disimpan minimal 10 tahun
- **PDPA / UU PDP** (UU 27/2022): customer data dengan consent + right to delete (hard delete untuk customer batal yang request)
- Akses customer data oleh staff ter-log

---

## 8. Struktur Repository Laravel

```
jerp/
├── app/
│   ├── Console/
│   │   └── Commands/                   # custom artisan commands
│   ├── Http/
│   │   ├── Controllers/                # thin (delegate ke Service / Livewire)
│   │   ├── Middleware/
│   │   ├── Requests/                   # FormRequest validation
│   │   └── Resources/                  # API resources
│   ├── Models/                         # shared models (User, Role, Project, Company, Bank)
│   ├── Modules/
│   │   ├── FO/
│   │   │   ├── MarketingSales/
│   │   │   ├── FinanceAR/
│   │   │   ├── Collection/
│   │   │   ├── Legal/
│   │   │   ├── Launching/
│   │   │   ├── KpaView/
│   │   │   ├── Komisi/
│   │   │   ├── Approval/
│   │   │   ├── Adminjual/
│   │   │   ├── Dashboard/
│   │   │   └── Settings/
│   │   ├── BO/
│   │   │   ├── GeneralLedger/
│   │   │   ├── AccountsReceivable/
│   │   │   ├── AccountsPayable/
│   │   │   ├── CashBank/
│   │   │   ├── Inventory/
│   │   │   ├── HR/
│   │   │   ├── FoBoBridge/
│   │   │   └── Setup/
│   │   ├── BankLoan/
│   │   │   ├── Contracts/
│   │   │   ├── ContractBanks/
│   │   │   ├── BIChecking/
│   │   │   ├── Realization/
│   │   │   ├── Retention/
│   │   │   ├── Buyback/
│   │   │   ├── Mom/
│   │   │   ├── Reminders/
│   │   │   └── Dashboard/
│   │   ├── Backup/
│   │   │   ├── Dashboard/
│   │   │   ├── Schedules/
│   │   │   ├── Logs/
│   │   │   ├── Reports/
│   │   │   └── Config/
│   │   └── System/
│   │       ├── Users/
│   │       ├── Roles/
│   │       ├── Masters/                # Project, Company, Bank, CostCenter
│   │       ├── Numerator/
│   │       ├── Settings/
│   │       └── AuditLog/
│   ├── Services/
│   │   ├── Payment/
│   │   │   └── XenditService.php
│   │   ├── Bank/
│   │   │   ├── BCA/
│   │   │   ├── BNI/
│   │   │   └── ...
│   │   ├── Pdf/
│   │   │   └── BrowsershotRenderer.php
│   │   ├── Notification/
│   │   │   ├── TrelloService.php
│   │   │   └── WhatsAppChannel.php
│   │   └── Numerator/
│   │       └── NumeratorService.php
│   ├── Events/
│   ├── Listeners/
│   ├── Policies/
│   ├── Providers/
│   └── Filament/
│       ├── BO/                         # Filament panel BO
│       ├── Backup/                     # Filament panel Backup
│       └── System/                     # Filament panel System
├── bootstrap/
├── config/
│   ├── modules.php                     # per-modul config (feature flags, integrations)
│   ├── xendit.php
│   ├── tuxarch.php                     # backup config (legacy compat)
│   └── ...
├── database/
│   ├── factories/
│   ├── migrations/
│   │   ├── 0001_01_01_create_users_table.php       # shared
│   │   ├── 2025_05_28_001_create_fo_*.php          # FO tables (50+ migrations)
│   │   ├── 2025_05_28_100_create_bo_*.php          # BO tables
│   │   ├── 2025_05_28_200_create_bankloan_*.php    # BankLoan tables (port dari 103 EF migrations)
│   │   ├── 2025_05_28_300_create_backup_*.php      # Backup tables
│   │   └── 2025_05_28_400_create_audit_*.php
│   └── seeders/
│       ├── DatabaseSeeder.php
│       ├── RolesAndPermissionsSeeder.php
│       ├── MasterDataSeeder.php                    # bank, project, company
│       └── DemoDataSeeder.php                      # untuk dev / testing
├── lang/
│   ├── id/                                          # Indonesian (primary)
│   └── en/                                          # English (fallback / API errors)
├── public/
│   ├── index.php
│   └── storage/                                     # symlink
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   ├── components/                             # Blade components
│   │   ├── livewire/
│   │   │   ├── fo/
│   │   │   ├── bo/
│   │   │   └── bankloan/
│   │   ├── pdf/                                    # PDF templates (Blade → Browsershot)
│   │   │   ├── tts.blade.php
│   │   │   ├── bkm.blade.php
│   │   │   ├── ppjb.blade.php
│   │   │   ├── ajb.blade.php
│   │   │   ├── kpr-pengajuan.blade.php
│   │   │   ├── kpr-realisasi.blade.php
│   │   │   └── ...
│   │   ├── emails/                                 # Mail templates
│   │   │   ├── kpr-verify-code.blade.php
│   │   │   ├── kpr-forgot-password.blade.php
│   │   │   ├── kpr-report-kpi.blade.php
│   │   │   ├── backup-alert.blade.php
│   │   │   └── ...
│   │   └── nup/
│   │       └── daftar.blade.php
│   ├── css/
│   └── js/
├── routes/
│   ├── web.php                                     # global routes
│   ├── api.php
│   ├── channels.php                                # broadcasting (Reverb)
│   ├── console.php                                 # scheduled tasks
│   └── modules/
│       ├── fo.php
│       ├── bo.php
│       ├── bankloan.php
│       ├── backup.php
│       └── system.php
├── storage/
│   ├── app/
│   │   ├── public/                                 # foto, dokumen public
│   │   └── backups/                                # SQL dumps (atau S3)
│   └── logs/
├── tests/
│   ├── Feature/
│   │   ├── FO/
│   │   ├── BO/
│   │   ├── BankLoan/
│   │   └── Backup/
│   ├── Unit/
│   └── Browser/                                    # Dusk tests
├── docker/
│   ├── nginx/
│   ├── php/
│   ├── mysql/
│   └── redis/
├── .env.example
├── composer.json
├── package.json
├── vite.config.js
├── tailwind.config.js
├── phpunit.xml
├── pint.json                                       # code style
├── phpstan.neon                                    # Larastan config
└── README.md
```

---

## 9. Roadmap Migrasi

### Phase 0 — Setup (1-2 minggu)
- [x] Tulis PRD, ERD, CLAUDE (done — Anda di sini)
- [x] Setup repo Laravel 12 (`composer create-project laravel/laravel jerp`)
- [x] Setup Docker Compose dev environment (PHP-FPM, Nginx, MySQL 8, Redis, Meilisearch, Reverb)
- [x] Setup CI/CD pipeline (GitHub Actions: lint, test, build)
- [x] Setup Laravel ecosystem packages (lihat §2.2)
- [x] Setup Filament panels (BO, Backup, System)
- [x] Setup base auth + RBAC + multi-tenancy scaffolding

### Phase 1 — Foundation (3-4 minggu)
- [x] Implement modul **System** (Users, Roles, Permissions, Master Project/Company/Bank, Numerator, Settings, Audit Log)
- [x] Implement modul **Backup** (TuxArch UI → Filament + Spatie Backup integration)
- [x] Restore semua `.bak` legacy ke dev MySQL via tool migrasi (SSMA / custom Python script)
- [x] Generate semua migrations dari schema legacy → Laravel migration files
- [x] Seed data master (banks, projects, companies, default roles)

### Phase 2 — BankLoan (4-6 minggu)
- [ ] Port 103 EF migrations → Laravel migrations
- [ ] Implement domain models (Contract, ContractBank, BIChecking, RA, PR, RR, MoM)
- [ ] Implement UI (Filament + Livewire untuk form upload berkas)
- [ ] Implement workflow: Pengajuan → BI Checking → Interview → SP3K → Akad → RA → Retensi → RR
- [ ] Implement PDF templates (KPR Pengajuan, Realisasi, Akad)
- [ ] Implement reminder jobs (BI Checking, Pengajuan Retensi) via Horizon
- [ ] Implement dashboard KPI
- [ ] Migrate data dari `MUP_BankLoan` → MySQL

### Phase 3 — FO (8-10 minggu)
- [x] Implement **MarketingSales** (Customer, Unit, Reservasi, Kontrak, Skema, NUP, dst.)
- [ ] Implement **FinanceAR** (TTS, BKM, BG, VA, MEMO, FP, TTR)
- [ ] Implement **Collection** (Tunggakan, PJT, SKL, FollowUp)
- [ ] Implement **Legal** (PPJB, AJB, ST/BAST, IMB, Sertifikat)
- [ ] Implement **Launching** (NUP daftar, Reception, ClosingNUP, UnitPetaLive via Reverb, Cashier)
- [ ] Implement **Approval** workflow (ADJ, Batal, GN, GU, Diskon, Reschedule)
- [ ] Implement **Adminjual** (master Agent, Unit, PL, Skema, Gimmick, Peta site plan)
- [ ] Implement **Komisi** (CF, Komisi, Reward, BLT, Skom)
- [ ] Implement **NUP Public** (registration page + reCAPTCHA)
- [ ] Implement Xendit integration (VA, payment link, callback)
- [ ] Migrate data dari `IPRO_DL_FO_011MUP_*` → MySQL

### Phase 4 — BO (6-8 minggu)
- [ ] Implement **GL** (Account, Jurnal, Approval, Closing)
- [ ] Implement **AR/AP/CB** (Invoice, BKM/BKK/BGM/BGK/BS/MEMO/TTG/Kuitansi/BPPH)
- [ ] Implement **IVT** (MasterStok, FPB, PR, BKB, BPB, BBB)
- [ ] Implement **HR** (Karyawan, BRQ)
- [ ] Implement **FO-BO Bridge** (event listener untuk auto-posting jurnal dari FO)
- [ ] Implement print template semua voucher
- [ ] Migrate data dari `IPRO_DL_BO_011MUP` → MySQL

### Phase 5 — Dual-run & Cutover (4-6 minggu)
- [ ] Setup environment paralel: legacy (read-only) + new (read-write)
- [ ] ETL real-time / batch sinkronisasi data
- [ ] UAT dengan operator real
- [ ] Training operator
- [ ] Cutover modul-by-modul: BankLoan first (low traffic) → FO `financear` → FO `marketingjual` → BO (last)

### Phase 6 — Optimization & Hardening (ongoing)
- [ ] Performance tuning (query, index, cache)
- [ ] Security audit + penetration testing
- [ ] Documentation lengkap (operator manual, dev manual, API docs)
- [ ] Disaster recovery drill

**Total estimasi**: 6-9 bulan dengan tim 3-5 developer + 1 QA + 1 PM.

---

## 10. Risiko & Mitigasi

| Risiko | Probabilitas | Dampak | Mitigasi |
| --- | --- | --- | --- |
| Schema legacy banyak yang **tidak terdokumentasi** (kolom `xxx_old`, status code Indonesian magis) | High | Medium | Reverse engineer dari `.aspx` + interview operator + dual-run untuk validasi |
| **Dokumen cetak** harus persis sama dengan legacy (operator + customer sudah familiar) | High | High | Setup test khusus byte-comparison untuk PDF; sign-off dari operator |
| **Migrasi data** SQL Server → MySQL (DateTime offset, varchar collation, identity) | Medium | High | Pakai tool resmi (MySQL Workbench Migration Wizard / SSMA) + reconciliation script |
| **Stored procedure** (especially `SP_DashboardKpi`, `SP_FOtoStandAlone_*`) — kompleks | Medium | High | Port ke Eloquent + Service class, **bukan** ke stored procedure MySQL (sulit di-version) |
| **Performance regression** karena ORM (Eloquent N+1 risk) | Medium | Medium | Larastan rule + Laravel Debugbar di staging + load test sebelum cutover |
| Operator **menolak UI baru** | Medium | High | Pertahankan terminologi Indonesia + alur form yang sama; soft launch + training |
| Realtime SignalR → Reverb migration (Launching live unit) | Low | Medium | Reverb stable di Laravel 11+, dokumentasi memadai |
| Xendit webhook delivery failure | Low | Medium | Idempotency key + retry queue + reconciliation cron |

---

## 11. Out of Scope (v1)

- Multi-tenant SaaS (di-prepare via `tenant_id` tapi tidak aktif di v1)
- Native mobile app (web responsive cukup)
- Customer-facing portal pasca-pembelian (cek tagihan, dokumen, dst.) — phase 2
- BI / data warehouse (basic dashboard cukup; phase 2 → Metabase / Superset)
- Multi-bahasa UI (id-ID + en fallback; bahasa lain phase 2)
- Integrasi DJP e-Faktur Host-to-Host (tetap manual CSV)

---

## 12. Acceptance Criteria (untuk go-live tiap modul)

Setiap modul dianggap **production-ready** jika:

1. ✅ Semua workflow legacy yang relevan ter-cover (operator bisa kerja seperti biasa)
2. ✅ Dokumen cetak byte-compatible (sign-off operator)
3. ✅ Migrasi data 100% (zero data loss) + reconciliation OK
4. ✅ Test coverage ≥70% (feature test untuk happy path + edge case)
5. ✅ Performance test passed (target di §7.1)
6. ✅ Security scan passed (SAST + manual review untuk modul finansial)
7. ✅ Audit log lengkap (write actions ter-log)
8. ✅ Backup + restore drill sukses
9. ✅ Operator training selesai
10. ✅ Dokumentasi user manual + API docs lengkap

---

## 13. Deployment Docker (Production-first)

J-ERP **wajib** di-deploy via Docker. Tidak ada instalasi native PHP-FPM/MySQL/Redis langsung di host. Stage dev, staging, dan production semua memakai image yang sama (build once, promote everywhere).

### 13.1 Arsitektur container

```
                      ┌────────────────────┐
                      │   Reverse Proxy    │   ← Traefik / Caddy / Nginx external
                      │  (TLS termination) │     (opsional, di luar compose)
                      └─────────┬──────────┘
                                │ :80/:443
                                ▼
   ┌────────────────────────────────────────────────────────────┐
   │                    Docker Compose Network                   │
   │                                                             │
   │  ┌─────────┐   ┌─────────┐   ┌─────────┐   ┌──────────┐    │
   │  │  nginx  │──▶│   app   │   │ horizon │   │scheduler │    │
   │  │  :8080  │   │ PHP-FPM │   │ worker  │   │  cron    │    │
   │  └─────────┘   │  :9000  │   │ (Redis) │   │          │    │
   │       │        └────┬────┘   └────┬────┘   └────┬─────┘    │
   │       │             │             │             │          │
   │       └─────────────┼─────────────┼─────────────┘          │
   │                     ▼             ▼                         │
   │  ┌─────────┐   ┌─────────┐   ┌─────────┐   ┌──────────┐    │
   │  │  reverb │   │  mysql  │   │  redis  │   │meilisearch│   │
   │  │  :8088  │   │  :3306  │   │  :6379  │   │  :7700   │    │
   │  └─────────┘   └─────────┘   └─────────┘   └──────────┘    │
   │                     │             │             │          │
   │                     ▼             ▼             ▼          │
   │              ┌──────────────────────────────────┐          │
   │              │   Named volumes (persistent)     │          │
   │              │   mysql-data · redis-data ·      │          │
   │              │   meili-data · storage · backups │          │
   │              └──────────────────────────────────┘          │
   └────────────────────────────────────────────────────────────┘
```

### 13.2 Service breakdown

| Service | Image (target) | Purpose | Restart | Healthcheck |
| --- | --- | --- | --- | --- |
| `app` | custom multi-stage (PHP-FPM 8.3-alpine + composer + node build) | Jalankan PHP-FPM untuk Laravel app | `unless-stopped` | `php-fpm-healthcheck` script |
| `nginx` | `nginx:1.27-alpine` | Static asset + FastCGI ke `app:9000` | `unless-stopped` | `wget -qO- http://localhost/up` |
| `horizon` | sama dengan `app` (override command) | `php artisan horizon` worker | `unless-stopped` | `php artisan horizon:status` |
| `scheduler` | sama dengan `app` (override command) | Tick `php artisan schedule:run` setiap 60s | `unless-stopped` | proses cron tail |
| `reverb` | sama dengan `app` (override command) | `php artisan reverb:start` untuk WebSocket | `unless-stopped` | TCP `8088` |
| `mysql` | `mysql:8.4` | Database utama (DB `jerp`) | `unless-stopped` | `mysqladmin ping` |
| `redis` | `redis:7-alpine` | Cache, queue, session, lock | `unless-stopped` | `redis-cli ping` |
| `meilisearch` | `getmeili/meilisearch:v1.10` | Search (customer/unit/kontrak) | `unless-stopped` | `curl /health` |

**Tidak masuk compose (opsional, di luar)**:
- Reverse proxy + TLS (Traefik / Caddy / Cloudflare Tunnel) — di-handle infra layer.
- SMTP relay — pakai provider eksternal (Mailgun/SES/Postmark).
- S3 / MinIO backup target — bisa shared object storage.

### 13.3 Multi-stage Dockerfile (ringkasan)

Stage layout di `docker/php/Dockerfile`:

1. **`composer-deps`** (`composer:2-alpine`) — install PHP deps tanpa dev, dengan cache mount.
2. **`node-build`** (`node:20-alpine`) — `npm ci && npm run build` (Vite production bundle ke `public/build/`).
3. **`runtime`** (`php:8.3-fpm-alpine`) — base PHP-FPM:
   - Install PHP ext: `pdo_mysql`, `redis`, `bcmath`, `intl`, `gd`, `zip`, `opcache`, `pcntl`.
   - Install OS pkg: `tini`, `supervisor` (opsional), `mysql-client`, `git`, `curl`, `chromium` (untuk Browsershot PDF).
   - Copy hasil dari `composer-deps` (vendor) dan `node-build` (public/build).
   - Copy source code (excluding `.dockerignore` entries).
   - `php artisan storage:link && php artisan config:cache && php artisan route:cache && php artisan view:cache`.
   - `USER www-data`, `EXPOSE 9000`, entrypoint `tini` → `php-fpm`.

Detail file: lihat `docker/php/Dockerfile` (digenerate sekarang). Build:
```bash
docker build -t jerp/app:latest -f docker/php/Dockerfile .
```

### 13.4 Environment & secrets

- `.env.example` di repo, `.env` **tidak** di-commit.
- Production: pakai Docker secrets (`docker compose --env-file .env.prod`) atau secret manager (HashiCorp Vault, AWS Secrets Manager) yang mount sebagai file ke `/run/secrets/`.
- Variabel khusus container (override default Laravel):
  ```
  DB_HOST=mysql
  REDIS_HOST=redis
  MEILISEARCH_HOST=http://meilisearch:7700
  REVERB_HOST=reverb
  ```
- Build-time arg: `APP_VERSION`, `BUILD_DATE`, `VCS_REF` → di-stamp ke image label (OCI standard).

### 13.5 Volume & persistence

| Volume | Mount | Berisi |
| --- | --- | --- |
| `mysql-data` | `/var/lib/mysql` di `mysql` | Database files |
| `redis-data` | `/data` di `redis` | Redis AOF/RDB |
| `meili-data` | `/meili_data` di `meilisearch` | Search index |
| `storage` | `/var/www/html/storage` di `app`, `horizon`, `scheduler`, `nginx` (read-only untuk nginx) | User upload, log, session, framework cache, **PDF generated** |
| `backups` | `/var/www/html/storage/app/backups` di `app`, `scheduler` | SQL dumps + file backup (alternatif: bind ke host folder yang di-sync ke S3) |
| `bootstrap-cache` | `/var/www/html/bootstrap/cache` | Config/route/view cache (regenerate on deploy) |

**Code volume**: di **production**, source code masuk ke image (immutable). Di **dev**, bind mount `./:/var/www/html` untuk hot reload.

### 13.6 Production deployment flow

```bash
# 1. Build immutable image (di CI)
docker build -t registry.example.com/jerp/app:${GIT_SHA} -f docker/php/Dockerfile .
docker push registry.example.com/jerp/app:${GIT_SHA}

# 2. Di server production
export IMAGE_TAG=${GIT_SHA}
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --no-build

# 3. Migrate (zero-downtime — opsional pakai blue/green)
docker compose exec app php artisan migrate --force

# 4. Smoke test
curl -fsS https://jerp.example.com/up
```

### 13.7 Backup integration

- Modul Backup (lihat §4.4) tetap jalan di dalam `app` container, men-dump `mysql:3306` ke `/var/www/html/storage/app/backups` → di-sync ke S3 via job.
- Untuk backup engine-level (mysql binlog / xtrabackup) — opsional, sidecar container `mysqlbackup`, tidak masuk v1.

### 13.8 Operational checklist sebelum go-live

- [ ] Image di-scan (Trivy / Snyk) → CVE high/critical = 0.
- [ ] Image size <500 MB (alpine base) — bukan full Ubuntu.
- [ ] Healthcheck semua service hijau ≥ 5 menit pre-cutover.
- [ ] Volume backup strategy diuji (restore drill ke env staging).
- [ ] Resource limit di compose: `mem_limit`, `cpus` untuk tiap service.
- [ ] Log driver: `json-file` rotate (max-size=50m, max-file=5) atau forward ke Loki/CloudWatch.
- [ ] Reverse proxy menambahkan: HSTS, X-Frame-Options, CSP, gzip/brotli.
- [ ] Secret tidak ter-bake ke image (cek `docker history`).
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `OPCACHE_VALIDATE_TIMESTAMPS=0`.
- [ ] Reverb port `:8088` ter-proxy via WSS (TLS) di reverse proxy.
- [ ] Sentry DSN configured + first error tracked.

### 13.9 File konfigurasi di repo

File-file Docker yang ada di root repo J-ERP:

```
jerp/
├── docker-compose.yml                 # base — dev sufficient
├── docker-compose.prod.yml            # override prod (no bind mounts, resource limits)
├── docker-compose.override.yml.example# dev customization template
├── .dockerignore
└── docker/
    ├── php/
    │   ├── Dockerfile                 # multi-stage app image
    │   ├── php.ini                    # opcache, memory_limit, upload_max_filesize, post_max_size
    │   ├── php-fpm.conf               # pool config (pm.max_children dst.)
    │   ├── entrypoint.sh              # migrate-on-boot toggle, opcache prime
    │   └── healthcheck.sh
    ├── nginx/
    │   ├── nginx.conf                 # main config
    │   ├── default.conf               # vhost: gzip, brotli, static cache, FastCGI
    │   └── healthcheck.sh
    └── mysql/
        └── conf.d/
            └── jerp.cnf               # innodb_buffer_pool, max_connections, char-set utf8mb4
```

> Template Dockerfile + compose disertakan di repo snapshot ini ([Dockerfile](Dockerfile), [docker-compose.yml](docker-compose.yml), [.dockerignore](.dockerignore)). Salin ke repo Laravel target J-ERP saat phase 0 setup.
