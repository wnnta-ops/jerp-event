# Java Core System v2.0 — Property Developer ERP

> **Rebuild Java Core System Lama (Java Core System) — Laravel 13 + Vue 3 + Tailwind CSS + MariaDB + Redis.**
>
> Single source of truth dokumen perencanaan ada di:
> - [`../REBUILD_BLUEPRINT_LARAVEL13.md`](../REBUILD_BLUEPRINT_LARAVEL13.md)
> - [`../DATABASE_SCHEMA_LARAVEL13.md`](../DATABASE_SCHEMA_LARAVEL13.md)
> - [`../API_AND_UI_REFERENCE.md`](../API_AND_UI_REFERENCE.md)

---

## Quickstart Developer

### Prasyarat
- Docker + Docker Compose (atau Laravel Sail / Herd)
- (Opsional jika tidak pakai Docker) PHP 8.4, MariaDB 11, Redis 7, Node 20

### Setup pertama kali

```bash
# 1) Clone & masuk ke folder
cd ipro-fo

# 2) Copy environment
cp .env.example .env

# 3) Start docker (bundle: php-fpm, nginx, mariadb, redis, reverb, horizon, scheduler, minio, mailpit, meilisearch)
docker compose up -d

# 4) Install deps
docker compose exec app composer install
docker compose exec app npm install

# 5) Generate key + migrate + seed
docker compose exec app php artisan key:generate
docker compose exec app php artisan storage:link
docker compose exec app php artisan migrate --seed

# 6) Build frontend (atau jalan dev)
docker compose exec app npm run build
# atau saat development: docker compose exec app npm run dev
```

Buka <http://localhost:8000> — login dengan:
- Email: `admin@mup.local`
- Password: `Admin@123`

> **Ganti password segera setelah login pertama.**

### Service URL
| Service | URL |
|---|---|
| App | http://localhost:8000 |
| Vite dev | http://localhost:5173 |
| Horizon | http://localhost:8000/horizon |
| Telescope | http://localhost:8000/telescope |
| Mailpit (email viewer) | http://localhost:8025 |
| MinIO console | http://localhost:9001 (user: `minio` / pass: `minio123`) |
| Meilisearch | http://localhost:7700 |
| Reverb WebSocket | ws://localhost:8080 |

### Tanpa Docker (host langsung)

```bash
composer install
npm install

cp .env.example .env
# edit DB_HOST=127.0.0.1, REDIS_HOST=127.0.0.1

php artisan key:generate
php artisan migrate --seed

# 3 proses di terminal terpisah
php artisan serve
php artisan horizon
npm run dev
```

---

## Struktur Folder (Singkat)

```
app/
├── Console/                # Artisan command + scheduler
├── Domain/                 # Bounded context (DDD-lite)
│   ├── Foundation/         #   Audit, Numerator, Notification, Tenant
│   ├── Master/             #   Customer, Unit, Project
│   ├── Sales/              #   Hold, Booking, Contract
│   ├── Finance/            #   TTS, Memo, Kuitansi, VA, Faktur Pajak
│   ├── Collection/         #   Followup, Denda, PJT, Tunggakan, SP, SKL
│   ├── BankLoan/           #   KPA, SP3K, Akad, Realisasi, Retensi
│   ├── Legal/              #   PPJB, AJB, BAST, IMB, Sertifikat
│   ├── Commission/         #   Skema, perhitungan, payout
│   ├── Approval/           #   Engine generic
│   └── Reporting/
├── Http/                   # Controllers (Api/V1, Web), Requests, Resources, Middleware
├── Jobs/                   # Queueable jobs
├── Models/                 # (Re-export dari Domain/*/Models)
├── Notifications/
├── Policies/
├── Providers/
└── Support/                # Trait, Enum, Helper, ValueObject, Casts

resources/js/               # Inertia + Vue 3
├── Components/             #   UI/, Form/, Layout/, DataTable/
├── Pages/                  #   Auth/, Dashboard/, Master/Customer/, Sales/Booking/, ...
├── Composables/            #   useFilter, usePagination, useDebounce, useToast
├── Layouts/                #   AppLayout, AuthLayout
├── Stores/                 #   Pinia
├── Plugins/
├── types/                  # TS types
└── app.ts

database/
├── migrations/             # Urut topologis (lihat DATABASE_SCHEMA_LARAVEL13.md Lampiran B)
├── seeders/
└── factories/

docker/                     # Dockerfile + nginx + mysql init
routes/                     # web.php, api.php, channels.php, console.php
```

---

## Convention (Wajib Diikuti)

1. **Identifier kode** dalam Bahasa Inggris. Kolom DB snake_case English (`customer_id`, `due_date`).
2. **UI label** dalam Bahasa Indonesia.
3. **Money**: `DECIMAL(20,2)` di DB, `MoneyValueObject` di kode. Tampilan via `formatRupiah()` helper.
4. **Date**: UTC di DB, Asia/Jakarta di display.
5. **Soft delete** semua master + transaksi.
6. **Audit log** otomatis via Observer untuk semua mutation.
7. **Numerator** semua nomor dokumen via `NumeratorService::next($code, $context)`.
8. **Permission check** wajib di setiap controller/action (`$this->authorize(...)` atau `auth()->user()->can(...)`).
9. **State machine** untuk entitas dengan lifecycle (Booking, TTS, KPA, dll) — transisi dicek di service layer.
10. **Form validation** via FormRequest. **Tidak boleh** validasi inline di controller.
11. **Error response API** standar: `{error: {code, message, details, request_id}}`.
12. **Imports**: alias `@/*` ke `resources/js/*`. Tidak boleh `../../../` di JS.
13. **File size**: komponen > 300 LOC pecah, controller > 100 LOC pindah ke action/service.

---

## Skrip yang Sering Dipakai

```bash
# Test
composer test                    # pest semua
composer test:coverage           # dengan coverage report (min 70%)

# Lint & format
composer lint                    # apply pint
composer lint:test               # check only
composer analyse                 # phpstan level 6

# Frontend
npm run dev                      # vite dev server
npm run build                    # production build
npm run lint                     # eslint vue/ts

# Cache (production tuning)
php artisan optimize
php artisan optimize:clear

# Queue
php artisan horizon
php artisan queue:restart        # graceful reload setelah deploy

# Realtime
php artisan reverb:start

# Schedule (dev)
php artisan schedule:work
```

---

## Troubleshooting

**Port 3306/6379 sudah dipakai?**
Tambahkan `COMPOSE_PROFILES` atau ubah port di `docker-compose.yml` (`"33060:3306"`).

**Migration gagal "table already exists"**
`php artisan migrate:fresh --seed` (DANGER: drop semua tabel).

**Octane error setelah edit code**
`php artisan octane:reload` (atau set `OCTANE_HOT_RELOAD=true` di dev).

**Vue 3 hot reload tidak jalan**
Pastikan `npm run dev` jalan + container nginx forward port 5173.

---

## Roadmap & Sprint

Lihat [`REBUILD_BLUEPRINT_LARAVEL13.md` Lampiran B](../REBUILD_BLUEPRINT_LARAVEL13.md). Saat ini scaffold sudah selesai Iterasi 1 (Foundation) + Sample Customer module sebagai referensi.

| Iterasi | Status | Modul |
|---|---|---|
| 1 — Foundation | ✅ Scaffolded | Auth, RBAC, Tenant, Audit, Numerator, Notification base, Layout, Customer |
| 2 — Sales Core | 🚧 Stub | Unit, Booking, Contract, Pricelist, Scheme, Gimmick |
| 3 — Finance & AR | 🚧 Stub | TTS, Kuitansi, VA, Memo, Cashback, Faktur Pajak |
| 4 — Collection | 📋 Planned | Followup, Denda, PJT, Tunggakan, SP, SKL |
| 5 — Bank Loan + Legal | 📋 Planned | KPA, SP3K, Akad, AJB, BAST |
| 6 — Komisi + Reporting | 📋 Planned | |
| 7 — Polish + Launch | 📋 Planned | |

---

## License & Ownership

Proprietary — PT. Mitra Usaha Propertindo (MUP). Tidak untuk distribusi publik.
