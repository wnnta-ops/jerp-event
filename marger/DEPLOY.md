# Deploy ke Server Test — J-ERP (FO) + ipro-fo (BO/Bankloan)

Model: **2 aplikasi + 1 MySQL + SSO**, diakses via **domain** (mis. `fo.test.com` = FO, `bo.test.com` = BO).
FO = J-ERP (Filament). BO + Bankloan = ipro-fo (Inertia/Vue). Keduanya 1 MySQL (`jerp-mysql`, database `jerp` + `ipro_bo`).

```
                     ┌─────────── Reverse Proxy (nginx / Cloudflare) ───────────┐
   fo.test.com  ───► │ → J-ERP nginx (port 8081)                                │
   bo.test.com  ───► │ → ipro-fo nginx (port 8090)                              │
                     └──────────────────────────────────────────────────────────┘
        J-ERP stack ──┐                                   ┌── ipro-fo stack
        app/nginx/horizon/reverb/meili/redis              app/nginx/redis
                      └──────── jerp-mysql (jerp + ipro_bo) via network shared-db ───┘
```

---

## 0. Prasyarat server
- Docker + Docker Compose v2.24+ (dukung `!override`/`!reset`).
- Domain `fo.test.com` & `bo.test.com` mengarah ke IP server (DNS A record).
- Reverse proxy (nginx host / Cloudflare Tunnel / Traefik) untuk TLS + routing 2 domain.
- Kode J-ERP + ipro-fo di server (git clone / scp).

---

## 1. Konfigurasi `.env` (WAJIB sebelum up)

### J-ERP `.env`
```env
APP_URL=https://fo.test.com
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=jerp
DB_USERNAME=jerp
DB_PASSWORD=<kuat>
# Integrasi BO ipro-fo:
IPRO_BO_URL=https://bo.test.com
IPRO_APP_KEY=base64:<APP_KEY ipro-fo — HARUS sama dgn ipro-fo>
SSO_SHARED_SECRET=<random 64 hex — SAMA di kedua app>
# Real-time (kalau dipakai): VITE_REVERB_HOST=fo.test.com, VITE_REVERB_SCHEME=https, VITE_REVERB_PORT=443
```

### ipro-fo `.env`
```env
APP_URL=https://bo.test.com
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<JANGAN regenerate — kalau berubah, enkripsi NIK/NPWP rusak>
DB_CONNECTION=mysql
DB_HOST=jerp-mysql        # MySQL J-ERP via network shared-db
DB_DATABASE=ipro_bo
DB_USERNAME=jerp
DB_PASSWORD=<sama dgn J-ERP>
FO_URL=https://fo.test.com
SSO_SHARED_SECRET=<SAMA dgn J-ERP>
SANCTUM_STATEFUL_DOMAINS=bo.test.com
SESSION_SAME_SITE=lax
```

> ⚠️ **Tiga nilai yang WAJIB konsisten**: `SSO_SHARED_SECRET` (sama di 2 app), `IPRO_APP_KEY` di J-ERP = `APP_KEY` ipro-fo, dan **jangan** `php artisan key:generate` di ipro-fo (merusak data terenkripsi).

---

## 2. Langkah deploy (otomatis via skrip)
Dari folder J-ERP, jalankan `marger/deploy.sh` (sesuaikan path di atasnya):
```bash
bash marger/deploy.sh
```
Skrip melakukan: network `shared-db` → up J-ERP → buat DB `ipro_bo` + grant → up ipro-fo → migrate+seed keduanya → npm build ipro-fo → backfill data FO→BO → restart nginx ipro.

### Atau manual:
```bash
# 1. Network bersama + up J-ERP
docker network create shared-db 2>/dev/null || true
docker compose up -d --build
docker network connect shared-db jerp-mysql 2>/dev/null || true

# 2. DB ipro_bo + grant (di jerp-mysql)
docker exec jerp-mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e \
 "CREATE DATABASE IF NOT EXISTS ipro_bo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
  GRANT ALL ON ipro_bo.* TO 'jerp'@'%'; GRANT SELECT ON jerp.* TO 'jerp'@'%'; FLUSH PRIVILEGES;"

# 3. Up ipro-fo (3 file -f) + migrate/seed/build
cd <ipro-fo dir>
FILES="-f docker-compose.yml -f docker-compose.override.yml -f docker-compose.shared.yml"
docker compose $FILES up -d --build app nginx redis
docker compose $FILES exec -T app composer install --no-dev --no-interaction
docker compose $FILES exec -T app php artisan migrate --seed --force
docker compose $FILES exec -T app sh -c "rm -rf node_modules package-lock.json && npm install --legacy-peer-deps && npm run build"
docker compose $FILES exec -T app php artisan config:cache

# 4. Migrate J-ERP + backfill data FO→BO
cd <J-ERP dir>
docker exec jerp-app php artisan migrate --force
docker exec jerp-app php artisan ipro:backfill all
```

---

## 3. Reverse proxy (contoh nginx host)
```nginx
server { server_name fo.test.com; location / { proxy_pass http://127.0.0.1:8081; include proxy_params; } }
server { server_name bo.test.com; location / { proxy_pass http://127.0.0.1:8090; include proxy_params; } }
```
Lalu pasang TLS (certbot). Atau pakai Cloudflare Tunnel: dua hostname → service `http://localhost:8081` & `http://localhost:8090`.

---

## 4. Verifikasi pasca-deploy
1. `https://fo.test.com/login` → login `admin@jerp.test` → hub (Front Office / Back Office / Backup).
2. Klik **Back Office** → SSO → masuk `https://bo.test.com` tanpa login lagi.
3. Di BO → sidebar **Kembali ke Dashboard J-ERP** → balik ke hub.
4. BO → Master Unit: 242 unit FO (cluster/tipe/LT-LB terisi). KPR Inisiasi → "Mulai Inisiasi" → TTS muncul.

> SSO cocokkan **email** FO↔BO. `admin@jerp.test` dipetakan ke `admin@mup.local` di `config/sso.php → email_map`. Tambah user lain di peta itu atau samakan email.

---

## 5. Gotcha penting
- **Bug rollup musl** (Alpine): `npm run build` ipro-fo gagal `MODULE_NOT_FOUND` → fix `rm -rf node_modules package-lock.json && npm install --legacy-peer-deps && npm run build` (sudah di skrip).
- **Restart container ipro-fo-app** ubah IP → nginx ipro-fo 502 → `docker restart ipro-fo-nginx` juga.
- **Mapping COA** (`config/ipro.php → coa_map`) masih best-effort + jurnal status **draft**. Verifikasi akuntan sebelum `IPRO_GL_STATUS=posted`.
- Migrasi di server **bersih** → tak ada inkonstistensi seperti lokal.
- Backup DB sebelum tiap deploy ulang.
