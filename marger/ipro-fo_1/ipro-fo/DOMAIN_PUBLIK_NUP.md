# 🌐 Domain Publik — Pendaftaran NUP (Tailscale Funnel)

Form NUP kini punya **domain terpisah, gratis, dan URL tetap** untuk diakses customer eksternal
**tanpa login**, lewat **Tailscale Funnel**. Datanya tetap masuk ke Java Core System.

---

## ✅ URL Publik (bagikan ke customer)

### `https://java-residence.tail212f65.ts.net/nup/create`

| Item | Nilai |
|------|-------|
| **Sifat** | Publik, tanpa login, tampilan standalone (tanpa menu admin) |
| **URL** | **Tetap** (tidak berubah-ubah seperti tunnel Cloudflare) |
| **HTTPS** | ✅ Otomatis (sertifikat Tailscale) |
| **Halaman peringatan** | ❌ Tidak ada (langsung ke form) |
| **Biaya** | Gratis (Tailscale Funnel free tier) |
| **Data masuk** | ✅ Otomatis ke modul Customer & NUP di Java Core System |

> Domain admin internal tetap terpisah: `https://contains-intervention-tours-purse.trycloudflare.com`
> (khusus tim, butuh login). Halaman internal **tidak** bisa diakses lewat tanpa login.

---

## ▶️ Menyalakan / mematikan

**Otomatis:** Funnel berjalan sebagai background service Tailscale → **aktif lagi otomatis** saat PC dinyalakan (selama Tailscale & `php artisan serve` jalan).

**Manual (jika mati):** klik dua kali **`start-public.bat`** — memastikan web server + Funnel hidup.

**Mematikan pintu publik:**
```
"C:\Program Files\Tailscale\tailscale.exe" funnel --https=443 off
```

**Cek status:**
```
"C:\Program Files\Tailscale\tailscale.exe" funnel status
```

---

## 📌 Syarat tetap online
1. **PC kantor menyala** + terhubung internet.
2. **`php artisan serve`** (port 8000) jalan — `start-public.bat` memastikan ini.
3. **Tailscale** login & aktif (cek ikon Tailscale di tray; akun `bagusalim.bagusalim@`).

---

## 🔧 Mengubah nama URL (opsional)
URL berasal dari nama device Tailscale (`java-residence`) + tailnet (`tail212f65.ts.net`).
- Ubah nama device di **https://login.tailscale.com/admin/machines** → URL ikut berubah.
- Tailnet bisa diberi nama custom di **Admin → Settings → Tailnet name** (mis. `javaresidence.ts.net`)
  sehingga URL jadi `https://java-residence.javaresidence.ts.net/...` (tetap gratis).

---

## 🔒 Keamanan
- Hanya **route publik** (`/nup/create`, `/nup/daftar`, `/nup/terima-kasih`, `/regions`) yang terbuka tanpa login.
- Semua halaman admin tetap **wajib login**.
- Form publik **dibatasi 15 submit/menit** per IP (anti-spam). Untuk produksi, pertimbangkan tambah **CAPTCHA**.
- `APP_DEBUG=false` (tidak membocorkan error/credential).
