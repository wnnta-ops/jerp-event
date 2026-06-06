# J-ERP

**J-ERP** — Property Developer ERP. Clone modern dari sistem eProperty legacy (ASP.NET WebForms) ke arsitektur Laravel 12 + MySQL 8 + Redis + Filament 3 + Livewire 3 + Tailwind 4, dideploy via Docker.

Status: **Phase 0** (kerangka aplikasi). Modul transaksi (FO/BO/BankLoan) menyusul Phase 1-4 per [PRD §9](../jrp/PRD_cloning.md).

## Stack

| Lapisan | Teknologi |
| --- | --- |
| Bahasa | PHP 8.4 |
| Framework | Laravel 12 |
| Database | MySQL 8.4 (InnoDB, utf8mb4) |
| Cache/Queue/Session | Redis 7 |
| Admin UI | Filament 3 (panel BO + Backup + System) |
| Front-end UI | Livewire 3 + Alpine.js + Tailwind 4 |
| Queue worker | Laravel Horizon (Redis-backed) |
| WebSocket | Laravel Reverb |
| Search | Meilisearch (Scout driver) |
| Container | Docker + Docker Compose |

## Quick start

Prasyarat: Docker Desktop, Node 20+, Git. Tidak perlu PHP/Composer di host.

```bash
git clone <repo-url> jerp && cd jerp
cp .env.example .env
docker compose build
docker compose up -d
```

Kunjungi `http://localhost:8081` → redirect ke `/login`.

**Default credentials (dev only)**:
- Email: `admin@jerp.test`
- Password: `password`

## Service map (compose)

| Service | Port | Tujuan |
| --- | --- | --- |
| `nginx` | 8081 → 80 | Reverse proxy + static |
| `app` | 9000 (internal) | PHP-FPM |
| `horizon` | – | Queue worker (Laravel Horizon) |
| `scheduler` | – | `artisan schedule:run` loop |
| `reverb` | 8088 | WebSocket (broadcasting) |
| `mysql` | 3306 | Database `jerp` |
| `redis` | 6379 | Cache + session + queue |
| `meilisearch` | 7700 | Full-text search |

## 4 Menu utama

Setelah login:

- **Front Office** (`/fo`) — Marketing & Sales, Finance AR, Collection, Legal, Launching, Komisi. _Livewire/Blade._
- **Back Office** (`/bo`) — Akuntansi (GL, AR, AP, Cash & Bank, Inventory, HR). _Filament 3 panel._
- **Bank Loan** (`/bankloan`) — Proses KPR (port dari legacy .NET 7 API). _Livewire/Blade._
- **Backup** (`/backup`) — Manajemen backup database. _Filament 3 panel._

Plus **System** (`/system`) untuk Users / Roles / Master / Numerator / Settings.

## Spec sources

- [../jrp/PRD_cloning.md](../jrp/PRD_cloning.md) — Product requirements (4 modul, integrasi, NFR, fase migrasi).
- [../jrp/ERD_cloning.md](../jrp/ERD_cloning.md) — Schema MySQL target (prefix `fo_*/bo_*/bankloan_*`).
- [CLAUDE.md](CLAUDE.md) — Panduan untuk Claude Code saat bekerja di repo ini (konvensi, perintah, gotcha).
- Legacy snapshot di `../jrp/` adalah read-only reference (backup `.bak` + dokumentasi sistem lama).

## Perintah harian

```bash
# Status semua service
docker compose ps

# Tail log spesifik service
docker compose logs -f app
docker compose logs -f horizon

# Run artisan
docker compose exec app php artisan migrate
docker compose exec app php artisan tinker

# Run tests (Pest)
docker compose exec app ./vendor/bin/pest

# Build assets (Vite) — di host, npm sudah ada
npm run dev          # HMR
npm run build        # production

# Restart stack
docker compose restart

# Stop semua (volume tetap)
docker compose down

# Stop + wipe volumes (DESTRUCTIVE — kehilangan data MySQL/Redis/Meili)
docker compose down -v
```

## Yang sudah / belum ada (Phase 0)

✅ Bootstrap Laravel 12, semua paket ecosystem terpasang
✅ 8 service Docker compose (semua healthy)
✅ Auth multi-tenant scaffolding (single tenant: MUP)
✅ 12 default roles ter-seed
✅ Filament panel BO/Backup/System dengan brand J-ERP
✅ App shell + sidebar + topbar + dark mode + design tokens OKLCH
✅ Login Livewire branded + dashboard 4-card

⏳ Modul transaksi FO/BO/BankLoan/Backup belum diimplementasi (Phase 1-4)
⏳ Filament Resources (CRUD Users/Roles/dst) belum ditambahkan ke System panel
⏳ Migrasi data dari legacy `.bak` belum dijalankan
⏳ CI/CD, production override compose belum ditambahkan

## License

Proprietary — internal use only.
