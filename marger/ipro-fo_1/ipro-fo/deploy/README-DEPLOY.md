# Deploy Java Core System ke Oracle Cloud Always Free

Always-on (24/7), HTTPS gratis, **lepas dari laptop & ts.net**. Stack **Laravel 12 / PHP 8.4 / SQLite tidak diubah**.
Arsitektur: **Caddy** (web server + HTTPS otomatis) → **PHP 8.4 FPM** → **SQLite** (di disk VM, permanen).

> Yang dikerjakan **kamu** (perlu akun/kartu/SSH): buat VM, buka port, upload kode, jalankan script.
> Script di folder ini mengotomatiskan instalasi & rilis. Kartu Oracle hanya untuk verifikasi — resource **Always Free tidak ditagih**.

---

## 1) Buat VM (Oracle Cloud Console)
1. Daftar di **cloud.oracle.com** → pilih **Always Free**.
2. **Compute → Instances → Create Instance**:
   - **Image:** Canonical **Ubuntu 24.04**.
   - **Shape:** `VM.Standard.A1.Flex` (Ampere ARM, Always Free: s/d 4 OCPU / 24GB) — paling lega.
     *Bila "out of capacity", coba region/AD lain atau `VM.Standard.E2.1.Micro` (AMD).*
   - **SSH key:** upload public key kamu (`id_ed25519.pub`/`id_rsa.pub`). *(Bila belum punya: `ssh-keygen -t ed25519`)*.
   - Pastikan **Assign public IPv4** aktif.
3. Catat **Public IP** instance.

## 2) Buka port 80 & 443 (WAJIB)
Console → **Networking → Virtual Cloud Network → (VCN kamu) → Security Lists → Default Security List → Add Ingress Rules**:
- Source `0.0.0.0/0`, IP Protocol **TCP**, Destination Port **80**
- Source `0.0.0.0/0`, IP Protocol **TCP**, Destination Port **443**

*(Port 22/SSH sudah terbuka default. Firewall di dalam VM dibuka otomatis oleh `provision.sh`.)*

## 3) (Opsional) Domain gratis → HTTPS
HTTPS butuh domain yang menunjuk ke IP VM.
- **Gratis:** buka **duckdns.org** (login GitHub/Google) → buat subdomain mis. `javacore-mup` → set IP-nya = Public IP VM. Domain jadi `javacore-mup.duckdns.org`.
- **Punya domain sendiri:** buat **A record** → Public IP VM.

## 4) Upload kode + data ke VM
Dari komputermu (git-bash / PowerShell dengan OpenSSH). Ganti `IP` dengan Public IP.

**Cara A — git (disarankan, mudah update):**
```bash
# di lokal (folder ipro-fo), sekali saja:
git init && git add . && git commit -m "deploy"
# push ke repo PRIVATE GitHub milikmu, lalu di VM:
ssh ubuntu@IP "sudo mkdir -p /var/www/javacore && sudo chown ubuntu:ubuntu /var/www/javacore"
ssh ubuntu@IP "git clone <URL-REPO-PRIVATE> /var/www/javacore"
# DATA: .gitignore tidak menyertakan DB → kirim file SQLite terpisah:
scp database/database.sqlite ubuntu@IP:/var/www/javacore/database/
```

**Cara B — tanpa git (tarball + scp):**
```bash
# di lokal, dari dalam folder ipro-fo:
tar --exclude=vendor --exclude=node_modules --exclude=public/build -czf /tmp/javacore.tgz .
scp /tmp/javacore.tgz ubuntu@IP:~
ssh ubuntu@IP "sudo mkdir -p /var/www/javacore && sudo chown ubuntu:ubuntu /var/www/javacore && tar xzf ~/javacore.tgz -C /var/www/javacore"
# (tarball ini SUDAH menyertakan database/database.sqlite = datamu)
```

## 5) Provisioning (sekali)
```bash
ssh ubuntu@IP
cd /var/www/javacore
bash deploy/provision.sh
```

## 6) Konfigurasi & rilis
```bash
# .env produksi
cp deploy/.env.production.example .env
nano .env      # isi APP_KEY (SAMA dgn .env lokalmu) + APP_URL=https://domainmu

# build + migrate + izin + online
bash deploy/deploy.sh
```

## 7) Pasang Caddy (web + HTTPS otomatis)
```bash
nano deploy/Caddyfile                 # ganti 'javacore-mup.duckdns.org' → domainmu
sudo cp deploy/Caddyfile /etc/caddy/Caddyfile
sudo systemctl reload caddy
```
Caddy otomatis mengambil sertifikat HTTPS (Let's Encrypt) dalam beberapa detik. Buka **https://domainmu** → halaman login muncul. ✅

## 8) Backup + (opsional) worker
```bash
# backup SQLite tiap jam
( crontab -l 2>/dev/null; echo "0 * * * * /var/www/javacore/deploy/backup-sqlite.sh >/dev/null 2>&1" ) | crontab -

# worker antrian — OPSIONAL (hanya bila nanti pakai QUEUE async; sekarang QUEUE=sync, tak perlu)
# sudo cp deploy/javacore-worker.service /etc/systemd/system/
# sudo systemctl daemon-reload && sudo systemctl enable --now javacore-worker
```

---

## Update aplikasi di kemudian hari
```bash
ssh ubuntu@IP
cd /var/www/javacore
git pull        # (Cara A) atau upload ulang kode (Cara B)
bash deploy/deploy.sh
```

## Catatan
- **Selesai pindah → matikan ts.net.** Tak perlu lagi laptop nyala / `tailscale funnel`.
- **APP_KEY** harus sama dengan lokal agar data terenkripsi & sesi tetap valid. Jangan generate baru bila DB sudah berisi data.
- SQLite cocok untuk 1 perusahaan/user sedikit. Bila konkurensi tulis tinggi nanti, migrasi ke Postgres (kode tak berubah, cukup ganti `.env` + import data).
- Biaya **$0** selama dalam batas Always Free. Jangan lampaui shape gratis (A1: ≤4 OCPU/24GB total; atau E2.1.Micro).
- Keamanan sudah ditangani: `APP_DEBUG=false`, Caddy blok `.env/.git/storage/database`, HTTPS dipaksa, header keamanan dasar.
