# 🌐 Panduan Akses Online — Java Core System

Software kini bisa diakses dari **mana saja** (HP, laptop, kantor lain) lewat
**Cloudflare Tunnel** — tanpa perlu server/VPS, tanpa buka port router, dan otomatis HTTPS.

---

## ✅ Status saat ini

| Item | Nilai |
|------|-------|
| **URL Publik (aktif sekarang)** | `https://contains-intervention-tours-purse.trycloudflare.com` |
| Login admin | `admin@mup.local` / `Admin@123` |
| Mode debug | **OFF** (`APP_DEBUG=false`) — aman, stack trace tidak bocor |
| Server lokal | `php artisan serve` di `127.0.0.1:8000` |

> ⚠️ **URL bersifat acak & sementara.** Selama proses `cloudflared` ini tidak dimatikan,
> URL tetap sama. Tapi jika PC restart / tunnel dijalankan ulang, **URL akan berganti baru**.
> Untuk URL tetap (mis. `fo.mup.co.id`), lihat bagian **Upgrade** di bawah.

---

## ▶️ Cara MENYALAKAN akses online (sekali klik)

1. Buka folder project: `C:\Users\SPV ACC MUP\Documents\Claude\FO\ipro-fo`
2. **Klik dua kali `start-online.bat`**
3. Tunggu beberapa detik. Cari baris seperti:
   ```
   https://xxxx-xxxx-xxxx.trycloudflare.com
   ```
4. **Itulah URL-nya.** Salin & bagikan / buka di perangkat mana pun.
5. **Biarkan jendela hitam itu terbuka** selama ingin tetap online.

## ⏹️ Cara MEMATIKAN akses online

- **Klik dua kali `stop-online.bat`**, atau
- Tutup jendela hitam `cloudflared`, atau tekan `Ctrl+C` di dalamnya.

Setelah dimatikan, software **tidak lagi bisa diakses dari internet** (tetap bisa dari PC ini via `http://localhost:8000`).

---

## 📌 Syarat agar tetap online

1. **PC kantor ini harus tetap menyala** dan terhubung internet.
2. Jendela `start-online.bat` (cloudflared) **tidak boleh ditutup**.
3. Jangan sleep/hibernate PC (atur Power Settings → Sleep → *Never* saat dicolok listrik).

Jika salah satu mati → URL publik langsung tidak bisa diakses sampai dinyalakan lagi.

---

## 🔒 Catatan keamanan (penting — ada data customer asli)

- Mode **debug sudah dimatikan**, jadi error tidak membocorkan isi sistem.
- Semua akses tetap **wajib login** — orang tanpa akun tidak bisa masuk.
- **URL bersifat semi-rahasia**: siapa pun yang tahu URL bisa membuka halaman login.
  Bagikan hanya ke orang yang berhak. Ganti password admin default bila perlu.
- Quick Tunnel cocok untuk **demo / tim kecil / akses sementara**. Untuk produksi
  perusahaan jangka panjang, gunakan jalur **Upgrade** di bawah.

---

## ⬆️ Upgrade ke URL TETAP + lebih production (nanti)

Quick Tunnel = URL acak. Untuk URL tetap milik sendiri (mis. `https://fo.mup.co.id`):

**Opsi A — Named Tunnel Cloudflare (gratis, tetap di PC ini)**
1. Buat akun Cloudflare (gratis) + punya 1 domain (mis. `mup.co.id`).
2. Arahkan domain ke Cloudflare (ganti nameserver).
3. `cloudflared login` → `cloudflared tunnel create java-core` → set DNS → jalankan sebagai service Windows (auto-start saat boot).
4. Hasil: URL tetap + auto nyala saat PC hidup.

**Opsi B — Deploy ke VPS/Cloud (paling stabil, 24/7 tanpa PC kantor)**
- Sewa VPS (DigitalOcean / Hetzner / Biznet Gio / IDCloudHost) + domain.
- Pasang Nginx + PHP-FPM + MySQL + SSL; pindah DB SQLite → MySQL.
- Online 24/7 walau PC kantor mati. (Saya bisa siapkan skrip & panduannya kapan saja.)

> Tinggal bilang **"lanjut upgrade ke URL tetap"** atau **"deploy ke VPS"** dan saya bantu prosesnya.

---

## 🛠️ Troubleshooting

| Masalah | Solusi |
|---------|--------|
| URL tidak bisa dibuka | Pastikan jendela `cloudflared` & `php artisan serve` masih jalan. Jalankan ulang `start-online.bat`. |
| "419 Page Expired" saat login | Bersihkan cookie browser untuk domain trycloudflare, lalu coba lagi. |
| URL berganti sendiri | Normal untuk Quick Tunnel setiap restart. Pakai Named Tunnel (Opsi A) agar tetap. |
| Halaman lambat saat pertama | Wajar (cold start). Akses berikutnya lebih cepat. |
| Lupa URL terbaru | Lihat file `storage/logs/cloudflared.err.log`, cari baris `trycloudflare.com`. |
