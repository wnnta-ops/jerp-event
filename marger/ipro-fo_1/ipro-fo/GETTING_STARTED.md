# GETTING STARTED — Java Core System v2.0

> 5-minute setup guide untuk developer baru.

---

## 1. Cek struktur

Folder `ipro-fo/` saat ini sudah berisi:

```
ipro-fo/
├── app/                              ← code domain (DDD-lite)
│   ├── Domain/                       (Foundation, Master, Sales, Finance, Collection, ...)
│   ├── Http/                         (Controllers, Requests, Resources, Middleware)
│   ├── Models/User.php
│   ├── Providers/                    (App, Auth, Domain, Event, Broadcast)
│   ├── Support/                      (Traits, Casts, Enums, ValueObjects, Helpers)
│   └── Exceptions/
├── bootstrap/                        ← Laravel 13 bootstrap
├── config/                           (app, database, domain, sanctum, permission, horizon, broadcasting)
├── database/
│   ├── migrations/                   ← 18 migration files (foundation + semua modul utama)
│   ├── seeders/                      ← DatabaseSeeder + 9 per-modul seeder
│   └── factories/
├── docker/                           ← Dockerfile + nginx config
├── public/index.php
├── resources/
│   ├── css/app.css
│   ├── js/
│   │   ├── Components/UI/            (Button, Input, Badge, EmptyState, Toaster)
│   │   ├── Components/Layout/        (Sidebar, Topbar, CommandPalette)
│   │   ├── Layouts/                  (AppLayout, AuthLayout)
│   │   ├── Pages/                    (Auth/Login, Dashboard, Master/Customer)
│   │   ├── types/inertia.d.ts
│   │   ├── app.ts
│   │   └── bootstrap.ts
│   └── views/app.blade.php
├── routes/
│   ├── web.php
│   ├── api.php
│   ├── channels.php
│   └── console.php
├── tests/                            ← Pest 3 setup
│   ├── Unit/                         (NumeratorService, BookingStateMachine, Money VO)
│   ├── Feature/                      (CustomerApi, AuthApi)
│   ├── Pest.php
│   └── TestCase.php
├── docker-compose.yml
├── composer.json
├── package.json
├── tailwind.config.ts
├── vite.config.ts
├── tsconfig.json
├── phpunit.xml
├── phpstan.neon
├── pint.json
├── .env.example
├── .gitignore
└── README.md
```

---

## 2. Quickstart

### Opsi A — Docker (recommended)

```bash
cd ipro-fo
cp .env.example .env

# Build + start semua service
docker compose up -d --build

# Install PHP & JS deps (one-time)
docker compose exec app composer install
docker compose exec app npm install

# Generate key, migrate, seed
docker compose exec app php artisan key:generate
docker compose exec app php artisan storage:link
docker compose exec app php artisan migrate --seed

# Build assets (atau npm run dev untuk HMR)
docker compose exec app npm run build
```

App akan jalan di **http://localhost:8000**.

### Opsi B — Tanpa Docker (host langsung)

Prasyarat: PHP 8.4 + MariaDB 11 + Redis 7 + Node 20.

```bash
cd ipro-fo
cp .env.example .env

# Edit .env: ganti DB_HOST=127.0.0.1, REDIS_HOST=127.0.0.1

composer install
npm install

php artisan key:generate
php artisan migrate --seed

# Run di 3 terminal terpisah:
php artisan serve            # terminal 1: http://localhost:8000
php artisan horizon          # terminal 2: queue worker
npm run dev                  # terminal 3: vite HMR
```

---

## 3. Login

| Email | Password | Role |
|---|---|---|
| `admin@mup.local`         | `Admin@123` | SuperAdmin |
| `rina.sales@mup.local`    | `Admin@123` | Sales |
| `joko.smanager@mup.local` | `Admin@123` | SalesManager |
| `ani.finance@mup.local`   | `Admin@123` | Finance |
| `budi.fmanager@mup.local` | `Admin@123` | FinanceManager |
| `citra.collec@mup.local`  | `Admin@123` | Collection |
| `direktur@mup.local`      | `Admin@123` | Direktur |

> **WAJIB**: Ganti password setelah login pertama, terutama untuk environment selain local.

---

## 4. Yang sudah jalan

### Backend
- ✅ Auth (login web + API Sanctum bearer)
- ✅ RBAC 11 role + ~120 permission (Spatie Permission)
- ✅ Tenant scope (multi-tenant ready)
- ✅ Audit log otomatis (Observer + polymorphic table)
- ✅ Numerator service (CUST, BOOK, KON, TTS, KW, MEMO, PPJB, AJB, BAST, SKL, NUP, KPA, SP, PJT, FP)
- ✅ Idempotency middleware
- ✅ HMAC webhook verifier (untuk integrasi bank VA)
- ✅ Sample module **Customer** lengkap (Model + DTO + Action + Service + Repository + Resource + Policy + Event + Listener + Controller API & Web + FormRequest + Factory)
- ✅ Models skeleton untuk Unit, Booking, Contract, TTS, Memo, Kuitansi, Approval (siap di-extend)

### Frontend
- ✅ Inertia + Vue 3 + Tailwind v3.4 dengan token sesuai UI/UX Style Guide
- ✅ AppLayout (Sidebar collapsible + Topbar)
- ✅ Dark mode toggle (persisted ke localStorage)
- ✅ Command Palette (Cmd+K) terhubung ke `/api/v1/search`
- ✅ Halaman Login, Dashboard, Customer Index/Create/Show/Edit
- ✅ UI Components: Button, Input, Badge, EmptyState, Toaster (Sonner)

### Database
- ✅ 18 migration mencakup **~80 tabel** semua modul utama
- ✅ Permission seed (~120 slug)
- ✅ Demo data Java Residence: 3 cluster × 6 unit, 3 payment scheme, 7 user

### Testing
- ✅ Pest 3 setup (`tests/Pest.php`, `tests/TestCase.php`)
- ✅ Unit test: `NumeratorService`, `BookingStateMachine`, `Money` VO
- ✅ Feature test: Customer API CRUD + duplicate detection, Auth API login/logout

Run: `composer test` atau `vendor/bin/pest`

---

## 5. Mengembangkan module baru (template)

Ikuti pattern Customer di `app/Domain/Master/Customer/` saat menambah modul baru. Urutan:

1. **Migration** — tambah di `database/migrations/`.
2. **Model** di `app/Domain/<Group>/<Module>/Models/<Name>.php`, pakai trait `HasTenant`, `HasAuditTrail`, `SoftDeletes`.
3. **Enum** untuk status / lookup di `Enums/`.
4. **DTO** untuk input/output (readonly final class).
5. **Repository** untuk query khusus + paginate.
6. **Service** untuk business rule (mis. validasi, kalkulasi).
7. **Action** untuk use case (CreateX, UpdateX, ApproveX) — bungkus dalam DB transaction + fire event.
8. **FormRequest** untuk validasi HTTP.
9. **Resource** untuk transformasi output API.
10. **Policy** untuk authorization.
11. **Event + Listener** untuk side-effect (notifikasi, jurnal).
12. **Controller** (API + Web/Inertia) — thin, panggil Action.
13. **Routes** tambah di `routes/api.php` + `routes/web.php`.
14. **Permission seed** di `PermissionSeeder` + assign ke role di `RoleSeeder`.
15. **Factory + Seeder** untuk demo data.
16. **Test** (Unit untuk service, Feature untuk endpoint).
17. **Vue Page** di `resources/js/Pages/<Group>/<Module>/`.

---

## 6. Penting Sebelum Production

Lihat **Production Checklist** di [`../REBUILD_BLUEPRINT_LARAVEL13.md`](../REBUILD_BLUEPRINT_LARAVEL13.md#13-production-checklist).

Minimal yang wajib sebelum go-live:
- [ ] Reset semua password default
- [ ] Aktifkan HTTPS + HSTS
- [ ] Setup backup MariaDB
- [ ] Enable MFA untuk SuperAdmin/Direktur
- [ ] Pen-test
- [ ] Migrasi data dari `.bak` SQL Server (lihat plan di blueprint)

---

## 7. Referensi Dokumen

| Dokumen | Fungsi |
|---|---|
| [`../REBUILD_BLUEPRINT_LARAVEL13.md`](../REBUILD_BLUEPRINT_LARAVEL13.md)            | Master plan 13 bagian (arsitektur, security, optimasi, deployment, dst) |
| [`../DATABASE_SCHEMA_LARAVEL13.md`](../DATABASE_SCHEMA_LARAVEL13.md)                | Sketsa lengkap schema MySQL (referensi untuk schema yang belum di-migrate) |
| [`../API_AND_UI_REFERENCE.md`](../API_AND_UI_REFERENCE.md)                          | Catalog endpoint API + UI component + wireframe ASCII |
| [`../Business_Flow_IPRO_FO_v1.0.html`](../Business_Flow_IPRO_FO_v1.0.html)         | Diagram alur bisnis 12 section (sumber asli) |
| [`../ERD_Sistem_IPRO_FO_v1.0.html`](../ERD_Sistem_IPRO_FO_v1.0.html)               | ERD database lama (untuk reverse engineering) |
| [`../UIUX_Style_Guide_IPRO_FO_v1.0.html`](../UIUX_Style_Guide_IPRO_FO_v1.0.html)   | Design system + wireframe |

---

## 8. Status Iterasi

| # | Iterasi | Status | Catatan |
|---|---|---|---|
| 1 | Foundation | ✅ | Auth, RBAC, Tenant, Audit, Numerator, Notification (base), Customer reference module |
| 2 | Sales Core | 🚧 | Model + migration siap; Action/Service/Controller TODO untuk Unit, Booking, Contract |
| 3 | Finance & AR | 🚧 | Model + migration siap; service layer TODO untuk TTS allocate/post, Kuitansi gen |
| 4 | Collection | 🚧 | Migration siap; command scheduler TODO |
| 5 | Bank Loan + Legal | 🚧 | Migration siap |
| 6 | Komisi + Reporting | 📋 | |
| 7 | Polish + Launch | 📋 | |

Total file Laravel di-generate: **~80+**.
Lines of code production: **~6500 LOC**.
