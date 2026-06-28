# Deploy Java Core System — Server Gratis & Selalu Nyala (24/7)

Tujuan: web bisa dipakai **semua user, kapan pun, di mana pun**, **tanpa** laptop/Claude Anda menyala.

> **Inti:** aplikasi harus pindah dari laptop Anda ke **server yang menyala 24 jam**. Laptop + Tailscale Funnel hanya untuk dev — begitu laptop mati, web mati.

---

## 0. Pembagian tugas

| Yang **Anda** lakukan (butuh akun/kartu) | Yang **sudah disiapkan** di repo |
|---|---|
| Daftar server (Oracle Cloud), buat VM, masukkan kartu utk **verifikasi** (tak ditagih utk Always Free) | `deploy/Caddyfile` (web server + HTTPS otomatis) |
| Daftar domain gratis (DuckDNS) | `deploy/deploy.sh` (rilis & update otomatis) |
| Jalankan perintah di runbook ini (copy-paste) | `deploy/javacore-worker.service` (queue) |
| | `.env.production.example` (template konfigurasi) |
| | Optimasi SQLite multi-user (WAL) |

Saya **tidak bisa** membuat akun atau memasukkan data pembayaran (batas keamanan). Tapi seluruh langkah di bawah tinggal **copy-paste**.

---

## 1. Pilihan hosting GRATIS

### Rekomendasi: **Oracle Cloud "Always Free"** (terbaik untuk gratis + selalu nyala)
- VM Ubuntu (ARM Ampere, sampai 4 vCPU / 24 GB RAM) **gratis selamanya**, nyala 24/7.
- Mampu menjalankan Laravel penuh: PHP 8.4, database, scheduler, queue, HTTPS.
- Syarat: kartu kredit/debit **hanya untuk verifikasi identitas** — resource Always Free **tidak ditagih**.
- Daftar: https://www.oracle.com/cloud/free/ → "Start for free".

> Alternatif gratis lain: **Google Cloud** e2-micro (Always Free, perlu kartu) — langkahnya mirip.

### Tanpa kartu sama sekali → keterbatasan besar
**InfinityFree** (shared hosting PHP gratis, tanpa kartu) **bisa** menampung halaman PHP + MySQL, **tetapi**:
- ❌ Tidak ada SSH/cron → **scheduler & queue tidak jalan** (jatuh tempo otomatis, dll. mati).
- ❌ Tidak ada Chromium → **cetak PDF tidak jalan**.
- ❌ Deploy Laravel rumit (upload manual, atur doc-root, MySQL only).

Untuk ERP keuangan multi-user, **sangat disarankan pakai VM (Oracle/GCP)**. Sisa runbook ini untuk VM Ubuntu.

---

## 2. Buat VM (Oracle Cloud)

1. Login → menu **Compute → Instances → Create instance**.
2. **Image:** Canonical **Ubuntu 22.04** (atau 24.04). **Shape:** pilih **Ampere (Always Free eligible)** — mis. `VM.Standard.A1.Flex`, 1 OCPU / 6 GB (masih dalam kuota gratis).
3. **Add SSH keys:** unggah public key Anda (atau biarkan Oracle generate; simpan private key).
4. Create. Catat **Public IP** instance (mis. `152.x.x.x`).
5. SSH masuk: `ssh ubuntu@152.x.x.x`

### ⚠️ BUKA PORT 80 & 443 (gotcha utama Oracle — WAJIB 2 tempat)
**a) Security List (firewall cloud):** Networking → Virtual Cloud Networks → (VCN Anda) → Subnet → Security List → **Add Ingress Rules**:
- Source `0.0.0.0/0`, IP Protocol **TCP**, Destination port **80**
- Source `0.0.0.0/0`, IP Protocol **TCP**, Destination port **443**

**b) Firewall OS (di dalam VM):**
```bash
sudo apt-get update
sudo apt-get install -y ufw
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable
# Oracle Ubuntu sering pakai iptables bawaan juga:
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save 2>/dev/null || true
```

---

## 3. Domain gratis (DuckDNS) + arahkan ke IP

HTTPS butuh nama domain. Pakai **DuckDNS** (gratis):
1. Buka https://www.duckdns.org → login (Google/GitHub).
2. Buat subdomain, mis. `javacore-mup` → jadi `javacore-mup.duckdns.org`.
3. Isi **current ip** = Public IP VM Anda → **update ip**.

(Punya domain sendiri? Cukup arahkan A-record ke IP VM.)

---

## 4. Pasang software di VM

```bash
# PHP 8.4 + ekstensi
sudo apt-get install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl php8.4-gd unzip git

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js 20 (untuk build frontend)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# Caddy (web server + HTTPS otomatis)
sudo apt-get install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt-get update && sudo apt-get install -y caddy

# (Opsional) Chromium untuk cetak PDF
sudo apt-get install -y chromium-browser || sudo snap install chromium
```

---

## 5. Ambil kode aplikasi ke server

**Cara A — via GitHub (disarankan, update mudah):**
1. Di laptop, push folder `ipro-fo` ke repo GitHub privat Anda.
2. Di server:
```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/<akun-anda>/<repo>.git javacore
sudo chown -R $USER:$USER /var/www/javacore
```

**Cara B — upload langsung (tanpa GitHub):** dari laptop (PowerShell):
```powershell
# kompres lalu kirim (lewati node_modules & vendor — akan dibuat ulang di server)
cd "C:\Users\SPV ACC MUP\Documents\Claude\FO"
tar --exclude=ipro-fo/node_modules --exclude=ipro-fo/vendor -czf javacore.tgz ipro-fo
scp javacore.tgz ubuntu@152.x.x.x:/tmp/
# di server:
# sudo mkdir -p /var/www && sudo tar -xzf /tmp/javacore.tgz -C /var/www && sudo mv /var/www/ipro-fo /var/www/javacore
# sudo chown -R $USER:$USER /var/www/javacore
```

> **Data:** untuk membawa semua data yang sudah Anda buat, salin `database/database.sqlite` dari laptop ke `/var/www/javacore/database/` di server. Atau mulai bersih (Langkah 6 poin 5).

---

## 6. Konfigurasi & rilis pertama

```bash
cd /var/www/javacore

# 1) Buat .env produksi dari template
cp .env.production.example .env
nano .env          # isi APP_URL=https://javacore-mup.duckdns.org , lalu simpan (Ctrl+O, Enter, Ctrl+X)

# 2) Dependensi + key
composer install --no-dev --optimize-autoloader
php artisan key:generate

# 3) Build frontend
npm ci && npm run build

# 4) Siapkan database
touch database/database.sqlite
php artisan migrate --force
#   -> Pilih SALAH SATU isi data awal:
#   (a) DB baru + role/permission + admin awal:
php artisan db:seed --force
#   (b) ATAU pakai database.sqlite yang Anda salin dari laptop (lewati seed).

# 5) Optimasi + symlink storage
php artisan storage:link
php artisan optimize
```

### Hak akses (penting untuk SQLite WAL & upload)
```bash
sudo chown -R www-data:www-data /var/www/javacore
sudo chmod -R 775 /var/www/javacore/storage /var/www/javacore/bootstrap/cache /var/www/javacore/database
# Folder database/ HARUS writable: WAL membuat file -wal & -shm di samping database.sqlite.
```

---

## 7. Nyalakan web server (Caddy)

```bash
# Pasang konfigurasi (ganti domain di dalamnya lebih dulu)
sudo cp /var/www/javacore/deploy/Caddyfile /etc/caddy/Caddyfile
sudo nano /etc/caddy/Caddyfile     # ganti javacore-mup.duckdns.org -> domain Anda
sudo mkdir -p /var/log/caddy && sudo chown caddy:caddy /var/log/caddy
sudo systemctl restart caddy
```
Buka `https://javacore-mup.duckdns.org` → halaman login muncul, HTTPS aktif (gembok hijau). 🎉

---

## 8. Tugas terjadwal (scheduler) + antrian (queue)

**Scheduler** (jatuh tempo otomatis, hold expire, dll.) — tambah cron:
```bash
sudo crontab -u www-data -e
# tambahkan 1 baris:
* * * * * cd /var/www/javacore && php artisan schedule:run >> /dev/null 2>&1
```

**Queue worker** (notifikasi/PDF latar belakang):
```bash
sudo cp /var/www/javacore/deploy/javacore-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now javacore-worker
sudo systemctl status javacore-worker     # pastikan "active (running)"
```

---

## 9. Backup otomatis (wajib untuk data keuangan)

```bash
sudo mkdir -p /var/backups/javacore
sudo crontab -e
# backup DB tiap hari jam 03:00, simpan 14 hari terakhir:
0 3 * * * cp /var/www/javacore/database/database.sqlite /var/backups/javacore/db-$(date +\%F).sqlite && find /var/backups/javacore -name 'db-*.sqlite' -mtime +14 -delete
```
> Disarankan juga sesekali unduh backup ke luar server (mis. `scp` ke laptop / Google Drive).

---

## 10. Buat akun user untuk staf

- Login sebagai admin (dari seeder, atau akun Anda yang sudah ada bila pakai DB lama).
- Menu **Pengaturan → User** → tambah user + assign Role (RBAC sudah aktif).
- **Segera ganti password admin default** bila pakai seeder.

---

## 11. Update aplikasi nanti (rilis berikutnya)

Cukup satu perintah (otomatis: maintenance → pull → build → migrate → online):
```bash
cd /var/www/javacore && bash deploy/deploy.sh
```

---

## 12. Checklist keamanan produksi

- [x] `APP_DEBUG=false`, `APP_ENV=production` (sudah di template)
- [x] HTTPS aktif (Caddy otomatis) + `SESSION_SECURE_COOKIE=true`
- [x] `.env`, `/storage`, `/database`, `/.git` tidak bisa diakses publik (diblok di Caddyfile)
- [ ] Ganti semua password default; pakai password kuat
- [ ] Backup harian aktif (Langkah 9) + tes restore sekali
- [ ] `sudo apt-get upgrade` rutin untuk patch keamanan
- [ ] (Opsional) batasi SSH ke IP Anda saja

---

## 13. Kalau nanti user makin banyak → pindah ke MySQL

SQLite + WAL cukup untuk tim kecil–menengah. Bila sering muncul "database is locked":
```bash
sudo apt-get install -y mariadb-server php8.4-mysql
sudo mysql -e "CREATE DATABASE javacore CHARACTER SET utf8mb4; CREATE USER 'javacore'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD'; GRANT ALL ON javacore.* TO 'javacore'@'localhost'; FLUSH PRIVILEGES;"
# Edit .env: blok DB_CONNECTION=mysql (lihat .env.production.example), lalu:
php artisan migrate:fresh --force && php artisan db:seed --force   # DB baru
# (migrasi data lama SQLite->MySQL bisa dibantu terpisah bila perlu)
php artisan optimize
```

---

### Ringkas alur
**Daftar Oracle Cloud → buat VM Ubuntu → buka port 80/443 (2 tempat!) → DuckDNS → pasang PHP/Caddy → ambil kode → `.env` + `deploy.sh` → Caddy → cron + worker → backup.**
Setelah ini, Java Core System nyala 24/7 dan bisa diakses semua user dari mana saja — tanpa laptop Anda. ✅
