# 🚀 MULAI DI SINI — Aktifkan Java Core System 24/7 (Gratis)

Ikuti **berurutan dari atas ke bawah**. Total ± 45–60 menit. Centang tiap selesai.
Perintah teknis lengkap (copy-paste) ada di **`DEPLOY.md`** — di sini panduan urutannya.

> 🔗 **Semua link yang Anda butuhkan (buka di tab baru):**
> | Untuk | Link |
> |---|---|
> | Daftar server gratis (Oracle) | https://signup.cloud.oracle.com/ |
> | Masuk Console Oracle (setelah daftar) | https://cloud.oracle.com/ |
> | Domain gratis (DuckDNS) | https://www.duckdns.org/ |
> | (Opsional) Simpan kode (GitHub) | https://github.com/signup |

---

## ☑️ FASE 1 — Daftar server gratis Oracle Cloud (± 15 menit)

1. Buka 👉 **https://signup.cloud.oracle.com/**
2. Pilih **Country/Territory: Indonesia**, isi **email** kerja → **Verify my email**.
3. Buka email dari Oracle → klik tautan verifikasi.
4. Isi **password** + data akun.
5. **Home Region:** pilih **Singapore (ap-singapore-1)** ← paling dekat & cepat dari Indonesia. *(Permanen, jangan salah pilih.)*
6. **Verifikasi HP** (kode SMS).
7. **Verifikasi kartu** (kredit/debit). ⚠️ Hanya untuk verifikasi — **Rp 0 ditagih** untuk resource *Always Free*. Kadang ada *hold* Rp ~15.000 yang otomatis kembali.
8. Selesai → tunggu email "Your account is ready" (beberapa menit s/d ½ jam).

✅ **Hasil:** Anda punya akun Oracle Cloud gratis.

---

## ☑️ FASE 2 — Buat server (VM Ubuntu) (± 10 menit)

1. Masuk 👉 **https://cloud.oracle.com/** (login akun tadi).
2. Menu ☰ (kiri atas) → **Compute → Instances → Create instance**.
3. **Name:** `javacore`.
4. **Image and shape → Edit:**
   - **Image:** Canonical **Ubuntu 22.04**.
   - **Shape → Change shape → Ampere** → pilih **VM.Standard.A1.Flex** → set **1 OCPU, 6 GB** (berlabel *Always Free eligible*).
   - *Kalau muncul "Out of host capacity":* ganti ke **VM.Standard.E2.1.Micro** (juga Always Free), atau ulangi beberapa saat lagi.
5. **Add SSH keys:** pilih **Generate a key pair for me** → **Download private key** (simpan file `.key`, JANGAN hilang).
6. Klik **Create**. Tunggu status **RUNNING**.
7. **Catat Public IP address** (mis. `152.x.x.x`).

✅ **Hasil:** Server menyala 24/7 + Anda punya Public IP & kunci SSH.

---

## ☑️ FASE 3 — Buka pintu internet (port 80 & 443) (± 5 menit) — JANGAN DILEWATI

> Oracle memblok port secara default. Harus dibuka di **2 tempat**.

**A) Firewall cloud (di Console Oracle):**
1. Di halaman instance → klik nama **Virtual Cloud Network**-nya.
2. **Subnet** → klik **Security List** default.
3. **Add Ingress Rules** → tambah 2 aturan:
   - Source `0.0.0.0/0`, Protocol **TCP**, Destination Port **80**
   - Source `0.0.0.0/0`, Protocol **TCP**, Destination Port **443**

**B) Firewall di dalam server:** lihat **`DEPLOY.md` §2** (blok perintah `ufw`/`iptables`) — jalankan setelah FASE 5 langkah SSH.

✅ **Hasil:** Web nanti bisa diakses dari internet.

---

## ☑️ FASE 4 — Domain gratis (DuckDNS) (± 3 menit)

1. Buka 👉 **https://www.duckdns.org/** → **Sign in** (Google/GitHub).
2. Di kotak **sub domain**, ketik nama, mis. `javacore-mup` → **add domain**.
   → Domain Anda jadi: **`javacore-mup.duckdns.org`**
3. Di baris domain itu, isi **current ip** = Public IP server (FASE 2) → **update ip**.

✅ **Hasil:** Alamat web Anda siap (gratis, ber-HTTPS nanti otomatis).

---

## ☑️ FASE 5 — Pasang & nyalakan aplikasi (± 20 menit, copy-paste)

1. **Masuk ke server** dari laptop. Buka **PowerShell** di Windows, jalankan:
   ```powershell
   ssh -i "C:\path\ke\file-kunci.key" ubuntu@152.x.x.x
   ```
   *(ganti path kunci & IP. Ketik `yes` saat ditanya pertama kali.)*
2. Sekarang ikuti **`DEPLOY.md`** berurutan, **copy-paste** tiap blok:
   - **§2** — firewall server (ufw/iptables)
   - **§4** — pasang PHP 8.4, Composer, Node, Caddy
   - **§5** — ambil kode aplikasi ke server *(Cara A GitHub, atau Cara B upload)*
   - **§6** — `.env` + key + build + migrate *(isi `APP_URL=https://javacore-mup.duckdns.org`)*
   - **§7** — nyalakan Caddy *(ganti domain di Caddyfile)*
   - **§8** — scheduler (cron) + queue worker
   - **§9** — backup harian otomatis

✅ **Hasil:** `https://javacore-mup.duckdns.org` terbuka — halaman login muncul, gembok HTTPS hijau. 🎉

---

## ☑️ FASE 6 — Pakai (± 5 menit)

1. Buka domain Anda → **login** admin.
2. Menu **Pengaturan → User** → buat akun untuk staf + atur Role.
3. **Ganti password admin default.**

✅ **SELESAI.** Java Core System aktif 24/7, semua user bisa akses dari mana saja, tanpa laptop Anda. 🚀

---

### Kalau tersangkut di langkah mana pun
Paste **perintah + output/error**-nya ke chat — saya bantu beresin. Atau minta saya **siapkan repo GitHub** (mempermudah FASE 5 Cara A) / **sesuaikan domain** Anda.
