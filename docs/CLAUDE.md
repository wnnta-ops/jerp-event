# claude_cloning.md

Panduan untuk Claude Code saat bekerja di **repo Laravel 12 hasil cloning** (bukan di folder snapshot ini). Companion: [PRD_cloning.md](PRD_cloning.md), [ERD_cloning.md](ERD_cloning.md).

> **Konteks**: folder yang sedang Anda baca (`Backup APP Eprop/`) adalah **snapshot deployment legacy eProperty**. Repo Laravel target adalah folder **terpisah** bernama **J-ERP** (mis. `jerp/` atau di GitHub `org/jerp`). Dokumen ini akan **dicopy ke `CLAUDE.md` di repo target** setelah project Laravel dibuat. Untuk konteks aplikasi legacy lihat [CLAUDE.md](CLAUDE.md) di folder ini.

---

## 1. Identitas Proyek

**Nama**: J-ERP — Property Developer ERP (clone modern dari eProperty legacy)
**Stack**: Laravel 12 (PHP 8.3+) · MySQL 8 · Redis 7 · Livewire 3 · Filament 3 · Tailwind 4 · Reverb
**Tenant awal**: MUP / Java Residence (migrasi dari ASP.NET legacy SQL Server)
**Bahasa UI**: Indonesia (id-ID), English fallback untuk error/dev
**Repository structure**: Monolith dengan **module-based** namespace (`app/Modules/{FO,BO,BankLoan,Backup,System}`)

---

## 2. Empat Menu Utama

Aplikasi memiliki **4 menu inti** + 1 menu sistem:

| Menu | Route prefix | Panel | Untuk siapa |
| --- | --- | --- | --- |
| **FO** (Front Office) | `/fo/*` | Livewire + Blade | Sales, Finance customer-facing, Collection, Legal, Launching, Komisi |
| **BO** (Back Office) | `/bo/*` | Filament 3 | Accounting (GL, AR, AP, CB, IVT, HR) |
| **BankLoan** (KPR) | `/bankloan/*` | Livewire + Filament hybrid | KPR Officer (proses kredit bank) |
| **Backup** (TuxArch replacement) | `/backup/*` | Filament 3 | IT Admin (DB backup management) |
| **System** (shared) | `/system/*` | Filament 3 | IT Admin (users, roles, master data, settings) |

Route publik (no auth): `/nup/daftar` untuk **NUP public registration** (calon pembeli mendaftar sendiri).

---

## 3. Perintah yang Sering Dipakai

> Asumsi Anda di root repo Laravel target (bukan folder snapshot ini).

### 3.1 Setup awal

```bash
# Clone & install
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan storage:link

# Database
php artisan migrate --seed       # WAJIB: pastikan DB MySQL kosong dulu untuk first migrate
php artisan migrate:fresh --seed # rebuild ulang dev DB (DESTRUCTIVE)

# Build assets
npm run build                    # production
npm run dev                      # dev with HMR (Vite)
```

### 3.2 Dev workflow

```bash
# Jalankan server dev (PHP-FPM mode via artisan)
php artisan serve

# Queue worker (Horizon) — WAJIB hidup di dev untuk job processing
php artisan horizon

# Scheduler (untuk testing cron jobs)
php artisan schedule:work        # foreground, untuk dev

# Real-time WebSocket
php artisan reverb:start         # Laravel Reverb untuk launching live map
```

### 3.3 Testing

```bash
# All tests
php artisan test
# atau pakai Pest langsung
./vendor/bin/pest

# Specific module
php artisan test --filter=FO\\MarketingSales
./vendor/bin/pest tests/Feature/FO/

# Parallel
php artisan test --parallel

# Coverage (butuh Xdebug)
php artisan test --coverage --min=70

# Single test
./vendor/bin/pest tests/Feature/FO/KontrakCreateTest.php
./vendor/bin/pest --filter="creates kontrak from reservasi"

# Browser tests (Dusk)
php artisan dusk
php artisan dusk tests/Browser/LaunchingLiveMapTest.php
```

### 3.4 Code quality (WAJIB sebelum commit)

```bash
# Format code
./vendor/bin/pint               # Laravel Pint, configured di pint.json

# Static analysis
./vendor/bin/phpstan analyse    # Larastan level 8

# Run all checks
composer check                   # custom script: pint + phpstan + test
```

### 3.5 Database operations

```bash
# Create new migration
php artisan make:migration create_fo_customers_table --create=fo_customers

# Rollback (DESTRUCTIVE di prod)
php artisan migrate:rollback
php artisan migrate:rollback --step=1

# Reset & re-seed (DEV only)
php artisan migrate:fresh --seed

# Schema dump (refresh consolidated schema)
php artisan schema:dump --prune

# Tinker (REPL)
php artisan tinker
```

### 3.6 Module scaffolding (custom command)

```bash
# Generate Module skeleton (custom artisan command yang harus dibuat)
php artisan module:make FO/MarketingSales
# → membuat app/Modules/FO/MarketingSales/{Controllers,Models,Services,Events,Listeners,Policies,Requests,DataTransferObjects}/
# → register di config/modules.php
# → buat routes/modules/fo/marketing-sales.php
```

### 3.7 Backup (TuxArch)

```bash
# Manual backup (sama dengan yang scheduler jalankan)
php artisan backup:run

# List backups
php artisan backup:list

# Cleanup retention
php artisan backup:clean

# Restore dari backup file (custom command)
php artisan backup:restore storage/app/backups/2026-05-28-00-30-00.zip
```

### 3.8 Horizon

```bash
php artisan horizon                # start workers (foreground, dev)
php artisan horizon:status         # check status
php artisan horizon:terminate      # graceful stop (prod akan auto-restart)
php artisan horizon:pause queue=default
php artisan horizon:continue queue=default
# Dashboard: http://localhost:8000/horizon (auth required)
```

### 3.9 Cache & optimization

```bash
# Dev: clear semua cache saat config berubah
php artisan optimize:clear

# Prod: cache config + routes + views
php artisan optimize
# atau granular:
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### 3.10 Filament

```bash
# Generate Resource (auto CRUD)
php artisan make:filament-resource Customer --panel=fo --generate
php artisan make:filament-resource JurnalHeader --panel=bo --generate

# Generate Widget (dashboard)
php artisan make:filament-widget BackupStatusWidget --panel=backup

# Re-publish assets
php artisan filament:assets
```

---

## 4. Arsitektur Tingkat Tinggi

### 4.1 Module-based monolith

```
app/Modules/
├── FO/
│   ├── MarketingSales/       # Customer, Unit, Reservasi, Kontrak, Skema
│   ├── FinanceAR/            # TTS, BKM, BG, VA, MEMO, FP, TTR
│   ├── Collection/           # Tunggakan, PJT, SKL, FollowUp
│   ├── Legal/                # PPJB, AJB, ST, IMB, Sertifikat
│   ├── Launching/            # NUP, Reception, ClosingNUP, Cashier (+ UnitPetaLive Reverb)
│   ├── KpaView/              # Tipis — sebagian besar pindah ke BankLoan
│   ├── Komisi/               # CF, Komisi, Reward, BLT
│   ├── Approval/             # Workflow approval kontrak
│   ├── Adminjual/            # Master Agent, PL, Gimmick, Peta site plan
│   ├── Dashboard/
│   └── Settings/
├── BO/
│   ├── GeneralLedger/
│   ├── AccountsReceivable/
│   ├── AccountsPayable/
│   ├── CashBank/
│   ├── Inventory/
│   ├── HR/
│   ├── FoBoBridge/           # Event listeners auto-posting jurnal
│   └── Setup/
├── BankLoan/
│   ├── Contracts/
│   ├── ContractBanks/
│   ├── BIChecking/
│   ├── Realization/          # RA
│   ├── Retention/            # PR + RR
│   ├── Buyback/
│   ├── Mom/
│   ├── Reminders/            # Hangfire → Horizon jobs
│   └── Dashboard/
├── Backup/
│   ├── Dashboard/
│   ├── Schedules/
│   ├── Logs/
│   ├── Reports/
│   └── Config/
└── System/
    ├── Users/
    ├── Roles/
    ├── Masters/              # Project, Company, Bank, CostCenter
    ├── Numerator/
    ├── Settings/
    └── AuditLog/
```

**Aturan**: setiap module folder MEMILIKI:
- `Controllers/` (atau `Livewire/` jika Livewire-based)
- `Models/`
- `Requests/` (FormRequest)
- `Services/` (business logic — JANGAN di Controller)
- `Events/` + `Listeners/`
- `Policies/` (authorization)
- `DataTransferObjects/` (untuk passing data antar layer)
- `routes.php` (di-include dari `routes/modules/<module>.php`)

### 4.2 Pemisahan tanggung jawab

```
Request → Route → Controller (thin) → FormRequest (validation)
                                    → Service (business logic)
                                    → Model (Eloquent + relationships)
                                    → Event (broadcast / sync to other module)
                                    → Listener (handle async via queue)
```

**Aturan**:
- **Controller TIPIS**: max ~50 baris, hanya delegasi ke Service
- **Service class** untuk logic kompleks (>10 baris atau lintas-model)
- **Eloquent Model**: relationship + accessor/mutator + scope; **TIDAK** business logic
- **Event/Listener**: untuk sync antar-modul (FO → BO posting jurnal, FO Kontrak → BankLoan customer mirror)
- **Job** (queueable): untuk pekerjaan async (PDF generation batch, email blast, backup, sync external)

### 4.3 Multi-tenant guardrail

Semua tabel transaksi punya `tenant_id`. Wajib **global scope** `TenantScope` di `Model`:

```php
abstract class TenantAwareModel extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
        static::creating(function ($model) {
            $model->tenant_id ??= app(TenantContext::class)->id();
        });
    }
}
```

Untuk bypass scope (admin only): `Model::withoutGlobalScope(TenantScope::class)`.

### 4.4 Cross-module communication

**JANGAN** call langsung Model dari modul lain di Controller/Service. Pakai:
1. **Events** untuk broadcasting state change → di-handle async oleh Listener di modul lain
2. **API contracts** (Service interface): inject via DI, di-bind di Service Provider per modul

Contoh:
```php
// app/Modules/FO/MarketingSales/Services/KontrakService.php
public function create(CreateKontrakDTO $dto): Kontrak
{
    $kontrak = Kontrak::create([...]);
    event(new KontrakCreated($kontrak));   // ← BankLoan & BO listen via async listeners
    return $kontrak;
}

// app/Modules/BankLoan/Listeners/SyncKontrakToBankLoan.php
class SyncKontrakToBankLoan implements ShouldQueue
{
    public string $queue = 'sync';
    public function handle(KontrakCreated $event): void { /* mirror to bankloan_contracts */ }
}
```

---

## 5. Aturan Penting Saat Bekerja

### 5.1 Bahasa & terminologi

- **UI string** WAJIB di `lang/id/*.php` (Indonesia). English hanya untuk error developer / API response code.
- **Istilah domain** PERTAHANKAN dalam Indonesia di:
  - Database column names (no_kontrak, tgl_ppjb, status_kpr)
  - Enum values (`'CICILAN'`, `'BOOKING'`, `'LUNAS'`, `'BATAL'`)
  - PDF template labels
  - UI label
- **English** hanya untuk struktur teknis (class name, method, route key).
- Lihat glossary di [CLAUDE.md §"Domain glossary"](CLAUDE.md) untuk referensi NUP, KPR, PPJB, dst.

### 5.2 Konvensi kode

- **Laravel Pint** sebagai formatter (config `pint.json` based on `laravel`)
- **Larastan level 8** sebagai static analyzer
- **Pest 3** untuk test (Feature + Unit + Browser via Dusk)
- **Snake_case** untuk DB columns, **camelCase** untuk PHP variables, **PascalCase** untuk classes
- **No raw SQL** kecuali via `DB::statement` dengan parameter binding (untuk migration / stored proc port)
- **Eloquent relationships** wajib di-define di Model (`hasMany`, `belongsTo`, `morphTo`)
- **Eager load** untuk hindari N+1: `$kontrak->load('customer', 'unit', 'skema')`
- **Explicit `select()`** untuk query yang heavy: jangan `SELECT *`
- **Soft delete** (`SoftDeletes` trait) di semua model transaksi
- **Audit columns** (`created_by`, `updated_by`, `deleted_by`) via custom trait `Auditable`

### 5.3 Form validation

- Pakai **FormRequest** classes, BUKAN inline `$request->validate()` di Controller
- Pesan error di `lang/id/validation.php` (Laravel sudah punya translasi resmi Indonesia)
- Custom rules di `app/Rules/` (mis. `ValidNik`, `ValidNpwp`, `ValidNomorKontrak`)

### 5.4 Authorization

- **Spatie Permission** sebagai dasar
- Permission naming: `module.area.action` (e.g. `fo.marketingjual.kontrak.create`)
- **Policies** per Model (`app/Modules/FO/MarketingSales/Policies/KontrakPolicy.php`)
- Wajib `authorize()` di Controller / Livewire action
- Multi-company scope: pakai middleware `EnsureUserCanAccessCompany` + Eloquent scope `forCompany()`

### 5.5 Numerator (penomoran dokumen)

**JANGAN PERNAH** generate nomor dengan `MAX(id) + 1` atau racy increment. Pakai:

```php
$noKontrak = app(NumeratorService::class)->next('KPR', companyId: $companyId);
// → 'KPR-2025-00042', atomic via DB transaction + row-level lock
```

Service: `App\Services\Numerator\NumeratorService`. Konfigurasi di tabel `numerators`.

### 5.6 PDF generation

- Default: **Browsershot** (Puppeteer-based) untuk template kompleks (PPJB, AJB, TTS, KPR docs)
- Fallback: **DomPDF** untuk template simple
- Template di `resources/views/pdf/*.blade.php`
- Naming: `pdf.tts`, `pdf.bkm`, `pdf.ppjb`, `pdf.kpr.pengajuan`, dst.
- Test wajib **byte-comparison** terhadap template legacy (operator + customer sudah familiar dengan layout)

### 5.7 Real-time (Reverb)

- Channel naming: `project.{projectId}.units` (presence), `user.{userId}.notifications` (private)
- Event di `app/Events/`, broadcasting via `ShouldBroadcast` interface
- Frontend: Livewire 3 + `wire:stream` untuk progress, atau Alpine.js + Echo untuk subscribe channel

### 5.8 Background jobs (Horizon)

- Pakai **queue connection `redis`** (Horizon default)
- Naming queue:
  - `default` — umum
  - `sync` — sync antar-modul (FO → BO, FO → BankLoan)
  - `pdf` — PDF generation
  - `email` — email send
  - `backup` — backup jobs
  - `whatsapp` — WA blast
  - `webhook` — webhook delivery (Xendit, dll.)
- Jobs **MUST** idempotent (bisa retry tanpa side-effect duplikat)
- Pakai `unique` lock di job kritis: `class SyncKontrakJob implements ShouldQueue, ShouldBeUnique`

### 5.9 Caching

- **Redis** sebagai default cache + session + queue + lock
- Key naming: `jerp:{tenant_id}:{module}:{type}:{id}`
- TTL default:
  - Dropdown master (banks, projects, accounts): 1 jam
  - Dashboard data: 5–15 menit
  - User permissions: 1 jam (auto-invalidate on role change)
- **Invalidate explicit** saat write: `Cache::tags(['kontrak', "company:{$id}"])->flush()`

### 5.10 Security

- **JANGAN PERNAH** trust user input. Selalu validasi via FormRequest.
- **JANGAN PERNAH** disable CSRF untuk web routes (kecuali webhook eksternal — pakai signature verification)
- **JANGAN PERNAH** expose Eloquent model di API response — pakai API Resources
- **JANGAN PERNAH** hardcode secret. Pakai `.env` + `config()`.
- File upload: validasi MIME + size + scan (opsional ClamAV)
- Password: BCrypt cost 12+ (Laravel default)
- 2FA wajib untuk role `super_admin`, `finance_manager`, `it_admin`
- Audit log untuk semua write actions (Spatie ActivityLog)

### 5.11 Migration ke MySQL dari legacy SQL Server

Saat butuh port schema dari legacy:
- Lihat **[ERD.md](ERD.md)** untuk schema legacy (SQL Server, multi-DB)
- Lihat **[ERD_cloning.md](ERD_cloning.md)** untuk target MySQL (single-DB, prefix per modul)
- Lihat **[ERD_cloning.md §11](ERD_cloning.md)** untuk type mapping table
- Tabel migration file harus **kronologis kontekstual** (mis. shared first, lalu fo_customers sebelum fo_reservasi, dst.)
- Untuk port KPR API: lihat **103 migration EF Core** di [ERD.md §3.10](ERD.md) — port ke Laravel migration **dalam urutan yang sama** (semantik identical)

### 5.12 Apa yang TIDAK boleh dilakukan

❌ Bypass authorization dengan `Model::withoutGlobalScope()` di luar context admin
❌ Pakai `DB::raw()` dengan string interpolation (SQL injection)
❌ Modify DB schema di production tanpa migration file
❌ Force-push ke `main` / `production` branch
❌ Commit `.env` atau file dengan credential
❌ Generate nomor dokumen dengan `MAX()+1` atau auto-increment murni
❌ Embed business logic di Eloquent Model (gunakan Service)
❌ Mocking database di Feature test untuk modul transaksi (pakai `RefreshDatabase` trait + real MySQL test DB)
❌ Hapus tabel atau drop kolom tanpa migration `down()` yang reversible
❌ Skip code formatter / static analysis ("commit later" → no, fix sekarang)

---

## 6. Glossary Singkat

Untuk istilah Indonesia di domain ini, lihat full glossary di [CLAUDE.md (legacy)](CLAUDE.md). Yang paling sering:

| Singkatan | Arti |
| --- | --- |
| NUP | Nomor Urut Pemilihan (queue number untuk hari launching) |
| KPR / KPA | Kredit Pemilikan Rumah / Apartemen |
| PPJB | Perjanjian Pengikatan Jual Beli (preliminary contract) |
| AJB | Akta Jual Beli (final deed) |
| BAST / ST | Berita Acara Serah Terima (handover) |
| IMB / PBG | Building permit |
| TTS / TTR / TTG / TTI | Tanda Terima (S=sementara, R=resmi, G=giro, I=internal) |
| BKM / BKK | Bukti Kas Masuk / Keluar |
| BGM / BGK | Bukti Giro Masuk / Keluar |
| BS | Bank Statement / Setor |
| FP / FPB | Faktur Pajak / Form Permintaan Barang |
| RA / PR / RR | Realisasi Akad / Pengajuan Retensi / Realisasi Retensi (KPR codes) |
| MEMO | Internal financial memo / cashback |
| VA | Virtual Account (bank-issued, per-customer) |
| BI Checking | Debtor history check at Bank Indonesia |
| MoM | Minutes of Meeting (developer ↔ bank) |
| FOBO | FO-BO bridge (auto posting jurnal FO → BO) |

---

## 7. Petunjuk Saat Memodifikasi

### Saat user minta tambah fitur baru:
1. **Tentukan modul**: FO / BO / BankLoan / Backup / System
2. **Tentukan sub-area** dalam modul (mis. `FO\MarketingSales`)
3. **Cek apakah butuh tabel baru** → migration file
4. **Cek apakah butuh sync ke modul lain** → Event/Listener
5. **Buat**: Model → FormRequest → Service → Controller/Livewire → Test → Filament Resource (jika BO/Backup/System) atau Blade view (jika FO)
6. **Update routes/modules/<module>.php**
7. **Update permissions** (seed di `RolesAndPermissionsSeeder`)
8. **Tulis test** (minimal happy path + 1 edge case)
9. **Run `composer check`** sebelum commit

### Saat user minta bug fix:
1. Reproduce bug dulu dengan test (failing test → fix → green test)
2. Cari root cause, jangan band-aid fix
3. Jika data corrupted: tulis migration / artisan command untuk repair, JANGAN edit DB manual di prod
4. Jika perlu rollback: pakai feature flag (tabel `settings`) sebelum revert code

### Saat user minta refactor:
1. **WAJIB ada test coverage dulu** sebelum refactor (safety net)
2. Refactor 1 modul / 1 sub-area sekali, JANGAN big-bang
3. Pakai `@deprecated` annotation untuk transisi (2 minggu) sebelum hapus old code
4. Update [PRD_cloning.md](PRD_cloning.md) jika ada perubahan arsitektur

### Saat user minta migrasi data dari legacy:
1. Backup MySQL dev DB dulu (`mysqldump`)
2. Tulis artisan command `php artisan migrate:legacy --table=<source> --target=<target>` di `app/Console/Commands/Legacy/`
3. Transformasi: status code mapping, encoding, datetime offset
4. Reconciliation script wajib (row count + sum amount)
5. Test di staging dengan data subset dulu, baru full migration

### Saat menulis test:
- **Feature test** untuk Controller / Livewire / Route (`tests/Feature/`)
- **Unit test** untuk Service / DTO / pure functions (`tests/Unit/`)
- **Browser test (Dusk)** untuk critical user flow (login, reservasi flow, kontrak creation, NUP registration)
- **Factory + Seeder** untuk data setup, JANGAN `Model::create([...])` manual di test
- Test naming: `it_creates_kontrak_from_active_reservasi`, `it_rejects_kontrak_when_unit_already_sold`

### Saat memodifikasi PDF template:
1. **WAJIB sign-off operator** sebelum deploy (visual diff dengan template legacy)
2. Test rendering di Browsershot dengan data real-like
3. Test print di kertas A4 + A5 + Letter (default operator)
4. Update version comment di Blade: `{{-- PDF Template: TTS v2.3 — 2026-06-15 --}}`

---

## 8. Lingkungan & Environment Variables

`.env.example` MUST include:

```
APP_NAME=J-ERP
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id
APP_FALLBACK_LOCALE=en

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=jerp
DB_USERNAME=jerp
DB_PASSWORD=secret

# Redis (cache, session, queue, lock)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PREFIX=jerp

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Broadcast (Reverb)
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST="localhost"
REVERB_PORT=8080

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"

# Filesystem
FILESYSTEM_DISK=local
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

# Search
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=

# Xendit
XENDIT_SECRET_KEY=
XENDIT_WEBHOOK_TOKEN=

# Google reCAPTCHA v3
RECAPTCHA_SITE_KEY=
RECAPTCHA_SECRET_KEY=
RECAPTCHA_MIN_SCORE=0.5

# Trello (untuk Backup alerts)
TRELLO_API_KEY=
TRELLO_TOKEN=
TRELLO_BOARD_ID=
TRELLO_LIST_ID=

# WhatsApp Provider (TBD)
WHATSAPP_PROVIDER=wablas
WHATSAPP_API_TOKEN=

# Backup
BACKUP_DESTINATION_DISKS=local,s3
BACKUP_NOTIFICATION_EMAIL=
BACKUP_RETENTION_DAYS=365

# Sentry / error tracking
SENTRY_LARAVEL_DSN=
```

---

## 9. Petunjuk Khusus Sesi

- Jika user bertanya tentang **legacy / existing system**, baca [CLAUDE.md](CLAUDE.md), [PRD.md](PRD.md), [ERD.md](ERD.md) di folder snapshot ini
- Jika user bertanya tentang **clone target / Laravel 12 baru**, baca file ini + [PRD_cloning.md](PRD_cloning.md) + [ERD_cloning.md](ERD_cloning.md)
- Jika user minta implement modul baru, **WAJIB** consult kedua PRD untuk memastikan logic legacy ter-cover
- Jika ada konflik antara PRD lama dan PRD cloning, **PRD cloning menang** (forward-looking)
- Jika user minta deploy, jangan eksekusi tanpa konfirmasi user pakai `AskUserQuestion` (deploy = high blast radius)
- Jika user minta hapus tabel / kolom production, **WAJIB** konfirmasi dulu + backup dulu
- File `.bak` di folder snapshot ini adalah **read-only reference**; jangan modify / hapus

---

## 10. Cara Cepat Onboard ke Repo

Saat Claude Code pertama kali masuk ke repo Laravel target:

1. Baca [README.md](README.md) di repo
2. Baca file ini (`CLAUDE.md`)
3. Run `composer install && npm install && cp .env.example .env`
4. Run `php artisan key:generate && php artisan migrate:fresh --seed`
5. Cek struktur:
   - `app/Modules/` — domain modules (FO/BO/BankLoan/Backup/System)
   - `database/migrations/` — chronological migrations
   - `routes/modules/` — per-module routes
   - `tests/Feature/` — per-module tests
   - `config/modules.php` — module registry & feature flags
6. Run `php artisan test` untuk pastikan test suite jalan
7. Run `composer check` untuk pastikan code quality OK
8. Buka browser: `php artisan serve` → `http://localhost:8000`
   - Login dengan `super_admin@example.test` / password seed
   - Cek 4 menu utama: FO, BO, BankLoan, Backup
