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

# 4. .env KEDUA untuk BO (aplikasi ipro-fo) — WAJIB, DB_PASSWORD harus SAMA dgn .env root
cp marger/ipro-fo_1/ipro-fo/.env.example marger/ipro-fo_1/ipro-fo/.env
nano marger/ipro-fo_1/ipro-fo/.env       # minimal: APP_ENV, APP_DEBUG=false, DB_PASSWORD

# 5. Naikkan FO — build + key:generate + migrate + seed
bash marger/up-fo.sh

# 6. Buat database BO (TIDAK dibuat otomatis — MySQL hanya bikin database `jerp`)
docker exec jerp-mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
  CREATE DATABASE IF NOT EXISTS ipro_bo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL ON ipro_bo.* TO \"jerp\"@\"%\"; FLUSH PRIVILEGES;"'

# 7. Naikkan BO — build + composer + migrate + build aset Vue
bash marger/up-bo.sh

# 8. Naikkan Portal Legal & Sales (database legal & sales dibuat otomatis)
bash marger/up-portal.sh
```

Boot pertama, entrypoint container `app` otomatis: `key:generate` (kalau APP_KEY kosong) →
`migrate --force` → `db:seed` (hanya bila DB kosong) → `storage:link` → cache prime.
Pantau: `docker compose logs -f app`.

### Kunci .env yang WAJIB diisi di server baru

| Kunci | Kenapa penting |
|---|---|
| `APP_KEY` | Biarkan **kosong** — dibuat otomatis saat boot pertama |
| `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD` | Password DB. `DB_PASSWORD` harus **sama** di `.env` root & `.env` BO |
| `IPRO_DB_*` | Boleh dibiarkan kosong → otomatis ikut koneksi utama (database `ipro_bo`) |
| `PG_USER`, `PG_PASSWORD` | Database portal Legal & Sales |
| `PORTAL_SSO_SECRET` | Sekali-login dari J-ERP ke Legal/Sales. Isi acak, samakan untuk semua app |
| `LEGAL_AUTH_SECRET`, `LEGAL_EXTERNAL_APPROVAL_SECRET` | Sesi & persetujuan eksternal Legal. **Kosong = endpoint approval menolak semua** |
| `SALES_NEXTAUTH_SECRET`, `SALES_NEXTAUTH_URL` | Login Sales. URL = `https://<domain>/sales/api/auth` |
| `REVERB_*`, `VITE_REVERB_HOST` | Peta unit real-time. **Ditanam saat build** — ubah nilainya = wajib build ulang |
| `XENDIT_SECRET_KEY`, `XENDIT_WEBHOOK_TOKEN` | Pembayaran UTJ. Token webhook harus cocok dgn yang di dashboard Xendit |
| `QONTAK_*` | WhatsApp. **Jumlah variabel template harus cocok** dengan template di Qontak, kalau tidak seluruh pesan ditolak 422 |
| `INSTALL_CHROMIUM=true` | Perlu untuk PDF (SPRS dikirim via email & WA) |

Buat nilai acak: `openssl rand -hex 32`

### Langkah 9 — akun & data awal

```bash
# Akun sysadmin portal Legal & Sales (ganti GANTI_PASSWORD)
docker exec jerp-legal node -e "const{PrismaClient}=require('@prisma/client');const b=require('bcryptjs');const p=new PrismaClient();(async()=>{const h=b.hashSync('GANTI_PASSWORD',10);await p.user.upsert({where:{email:'sysadmin@javaresidence.com'},update:{role:'SYSADMIN',isActive:true},create:{name:'IT Sysadmin',email:'sysadmin@javaresidence.com',passwordHash:h,role:'SYSADMIN',isActive:true}});console.log('ok');await p.\$disconnect()})()"

# Seed contoh portal — HANYA saat pertama, MENIMPA data
docker exec jerp-legal npm run db:seed
docker exec jerp-sales npx tsx prisma/seed.ts
```

### Langkah 10 — pastikan semuanya hidup

```bash
docker compose ps                                    # semua "Up", tidak ada "Restarting"
curl -I -s localhost:8081/login | head -1            # FO     → 200
curl -I -s localhost:8090/login | head -1            # BO     → 200/302
curl -I -s localhost:8081/legal/login | head -1      # Legal  → 200
curl -I -s localhost:8081/sales/login | head -1      # Sales  → 200

# Jembatan FO→BO tersambung? (paling sering luput di server baru)
docker exec jerp-app php artisan tinker --execute="
  \$c=config('database.connections.ipro_bo');
  echo 'host='.\$c['host'].' db='.\$c['database'].PHP_EOL;
  try { DB::connection('ipro_bo')->getPdo(); echo 'ipro_bo: BERHASIL'; }
  catch (Throwable \$e) { echo 'ipro_bo GAGAL: '.\$e->getMessage(); }"
```

Kalau `ipro_bo GAGAL` → database belum dibuat (langkah 6) atau `DB_PASSWORD` beda antara
`.env` root dan `.env` BO. **Jangan diabaikan**: sinkron master, posting jurnal, dan kiriman
ke Approval UTJ/DP semuanya lewat koneksi ini.

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

## Deploy harian (update dari git)

Sistem ini berisi **tiga aplikasi** yang bisa di-deploy TERPISAH. Jangan pakai
`docker compose up -d --build` polos — itu menyentuh semuanya sekaligus (termasuk
database) dan lebih lama.

### Langkah 1 — tarik kode & lihat apa yang berubah

```bash
cd ~/docker/jerp
git pull
git diff --name-only HEAD@{1} HEAD | cut -d/ -f1-2 | sort -u   # folder apa saja yang berubah
```

### Langkah 2 — jalankan skrip sesuai folder yang berubah

| Folder yang berubah | Aplikasi | Perintah |
|---|---|---|
| `app/`, `resources/`, `config/`, `database/`, `routes/` | **FO** (J-ERP) | `bash marger/up-fo.sh` |
| `marger/ipro-fo_1/...` | **BO** (ipro-fo) | `bash marger/up-bo.sh` |
| `app/legal/`, `app/sales/` | **Portal** Legal & Sales | `bash marger/up-portal.sh` |

Kalau dua-duanya berubah, jalankan dua-duanya (boleh berurutan). Ketiga skrip
**TIDAK menyentuh database MySQL** — container DB tidak di-restart.

Yang sudah dikerjakan otomatis oleh skrip:

- **up-fo.sh** — build image (autoloader kelas baru ikut ter-regenerasi), naikkan
  `app/nginx/horizon/scheduler/reverb`, `migrate --force`, restart nginx.
- **up-bo.sh** — build image, `composer install`, `migrate --force`, build aset Vue
  (`npm run build`), `config:cache`, restart nginx BO.
- **up-portal.sh** — build image Legal & Sales, naikkan postgres+legal+sales,
  `prisma db push`, restart nginx.

### Langkah 3 — pastikan hidup

```bash
docker compose ps                       # semua service harus "Up" (bukan Restarting)
curl -I -s localhost:8081/login | head -1   # FO   → 200
curl -I -s localhost:8090/login | head -1   # BO   → 200/302
docker compose logs --tail=50 app       # cek tak ada error saat boot
```

### Kapan butuh langkah TAMBAHAN

| Situasi | Perintah tambahan |
|---|---|
| Mengubah **`.env`** | `docker exec jerp-app php artisan config:clear` lalu `docker restart jerp-app jerp-horizon jerp-scheduler` |
| Hanya `docker restart jerp-app` (tanpa skrip) | Wajib `config:clear` dulu — config di-cache, nilai lama masih terpakai |
| Ubah kode **job/queue** (WA, email, sinkron BO) | `docker restart jerp-horizon` — job jalan di worker, BUKAN di web |
| Ubah **jadwal** (collection, backup) | `docker restart jerp-scheduler` |
| Ubah kode BO tanpa rebuild | `docker exec ipro-fo-app kill -USR2 1` (reload PHP; hindari restart yang sering zombie) |

> **Catatan penting:** migrasi FO jalan otomatis lewat skrip. Seed TIDAK pernah
> diulang (hanya saat DB kosong). `AUTO_MIGRATE=false` / `AUTO_SEED=false` di
> `.env` untuk mematikannya.

### Kalau gagal / ingin kembali ke versi sebelumnya

```bash
git log --oneline -5            # cari commit sebelumnya
git checkout <commit-sebelumnya>
bash marger/up-fo.sh            # atau up-bo.sh / up-portal.sh sesuai yang tadi
```

Migrasi TIDAK otomatis mundur. Bila versi bermasalah membawa migrasi dan perlu
dibatalkan: `docker exec jerp-app php artisan migrate:rollback --step=1 --force`
(cek dulu isi migrasinya — tidak semua aman dimundurkan).

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


docker container prune -f       # hapus semua container yang sudah berhenti (termasuk yang ber-hash)

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




bash marger/up-fo.sh
bash marger/down-fo.sh

bash marger/up-bo.sh
bash marger/down-bo.sh

bash marger/up-portal.sh
bash marger/down-portal.sh