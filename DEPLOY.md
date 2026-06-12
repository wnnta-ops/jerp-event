# Deploy J-ERP ke VPS

Target: **satu perintah** `docker compose up -d --build` langsung jalan (key + migrate +
seed otomatis di boot pertama). Dev lokal (Windows) tetap pakai `docker-compose.override.yml`
yang **tidak** ikut ke VPS (gitignored), jadi di server hanya base compose = mode produksi.

## Prasyarat VPS
- Linux (Ubuntu 22.04+), Docker Engine + plugin Compose **v2.24+** (`docker compose version`)
- Domain diarahkan ke IP VPS (untuk HTTPS + WebSocket Reverb)

## Setup awal (sekali)

```bash
# 1. Install Docker
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER          # lalu logout-login

# 2. Clone
git clone https://github.com/arieyogian/jerp.git
cd jerp

# 3. Buat .env (WAJIB sebelum `up` — kalau tidak, Docker bikin folder .env!)
cp .env.example .env
nano .env
#   APP_ENV=production   APP_DEBUG=false   APP_URL=https://erp.domain-anda
#   DB_PASSWORD=<kuat>   MYSQL_ROOT_PASSWORD=<kuat>
#   REVERB_SCHEME=https  VITE_REVERB_HOST=erp.domain-anda
#   REVERB_APP_KEY/SECRET=<acak>  MEILISEARCH_KEY=<acak>
#   APP_KEY=  (BIARKAN KOSONG — auto-generate saat boot pertama)

# 4. Jalankan — build image + auto key:generate + migrate + seed
docker compose up -d --build
```

Boot pertama, entrypoint container `app` otomatis: `key:generate` (kalau APP_KEY kosong) →
`migrate --force` → `db:seed` (hanya bila DB kosong) → `storage:link` → cache prime.
Pantau: `docker compose logs -f app`.

## Reverse proxy + TLS (host)

Container `nginx` dengar di `HTTP_PORT` (default 8080), Reverb di `REVERB_PORT` (8088).
Pasang reverse proxy host (Caddy/Traefik/Nginx) untuk TLS domain + WSS ke Reverb. Contoh **Caddy**:

```caddy
erp.domain-anda {
    reverse_proxy localhost:8080
}
# WebSocket Reverb (wss)
erp.domain-anda/app/* {
    reverse_proxy localhost:8088
}
```

## Update versi (deploy berikutnya)

```bash
cd jerp
git pull
docker compose up -d --build      # rebuild image + entrypoint migrate otomatis
```

> Catatan: migrasi jalan otomatis (`migrate --force`). Seed TIDAK diulang (hanya saat DB kosong).
> Set `AUTO_MIGRATE=false` / `AUTO_SEED=false` di `.env` untuk mematikan bila ingin manual.

## Perintah berguna

```bash
docker compose ps                                   # status service
docker compose logs -f app                          # log aplikasi
docker compose exec app php artisan migrate --force # migrasi manual
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan tinker          # REPL
docker compose exec app php artisan key:generate
docker compose exec app php artisan storage:link
docker compose exec app php artisan db:seed
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
docker compose exec app php artisan optimize:clear
docker compose down                                 # stop (data di named volume tetap)
docker compose down -v                              # stop + HAPUS data (hati-hati!)
```

## Backup data

Data persist di named volume Docker: `jerp_mysql-data` (DB), `jerp_storage` (upload: siteplan,
KTP/NPWP). Backup contoh:

```bash
docker compose exec mysql mysqldump -ujerp -p"$DB_PASSWORD" jerp > backup-$(date +%F).sql
docker run --rm -v jerp_storage:/data -v "$PWD":/out alpine tar czf /out/storage-$(date +%F).tgz -C /data .
```

## Reset database dari awal

> ⚠️ **DESTRUKTIF** — semua data (unit, pricelist, skema, NUP, customer, reservasi, dll)
> akan **HILANG** dan diganti data seeder awal. **Backup dulu** (lihat bagian atas) bila perlu.
> `APP_KEY` di `.env` tidak terganggu (file, bukan volume), jadi tak perlu generate ulang.

### Opsi A — Reset isi DB saja (paling umum)
Drop semua tabel → migrasi ulang → isi seeder awal (roles, user admin, master data).
File upload (siteplan/KTP di `storage`) **TIDAK** terhapus.

```bash
cd jerp
docker compose exec app php artisan migrate:fresh --seed --force
docker compose exec app php artisan optimize:clear
docker compose restart app
```

### Opsi B — Reset TOTAL (DB + Redis + Meilisearch + file upload)
Hapus **semua** named volume (data DB, cache, **dan file upload**), lalu build & boot ulang.
Saat boot, entrypoint otomatis migrate + seed (karena DB kosong).

```bash
cd jerp
docker compose down -v            # ⚠️ hapus SEMUA volume — termasuk siteplan/KTP yang diupload!
docker compose up -d --build
```

### Setelah reset
- Login pakai user admin hasil seeder (lihat kredensial di [README.md](README.md)).
- Data master yang Anda input manual (unit, pricelist, koordinat, siteplan, skema custom)
  **perlu di-input/import ulang** — seeder hanya mengisi data dasar.
- Cek: `docker compose exec app php artisan migrate:status` (semua "Ran").

> Catatan dev (lokal): perintah sama, tanpa `--force` pun jalan karena `APP_ENV=local`.
> Untuk dev cepat: `docker compose exec app php artisan migrate:fresh --seed`.

## Catatan dev vs prod

| | Dev (lokal Windows) | Prod (VPS) |
|---|---|---|
| File compose | base + `docker-compose.override.yml` | base saja |
| Source code | bind-mount `./` (hot reload) | di dalam image (immutable) |
| nginx | `nginx:alpine` + bind source | image `web` (public + aset Vite baked) |
| Migrate/seed | otomatis (dev) | otomatis boot pertama |
| `docker compose up` | `up -d` | `up -d --build` |

Dev yang baru clone: `cp docker-compose.override.yml.example docker-compose.override.yml`.


⚠️ Penting untuk deploy ke server (agar fitur baru muncul): setelah git pull → docker compose up -d --build, lalu sekali jalankan seeder untuk numerator & permission baru di DB lama:


docker compose exec app php artisan db:seed --class=MasterDataSeeder --force
docker compose exec app php artisan db:seed --class=AccessMatrixSeeder --force

(MasterDataSeeder = numerator SALES/BLT/PH/PU/dll; AccessMatrixSeeder = 114 permission untuk matrix Grup Akses.)