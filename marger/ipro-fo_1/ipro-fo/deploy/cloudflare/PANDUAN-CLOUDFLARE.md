# 🌐 Akses Publik via Cloudflare Tunnel — GRATIS, tanpa kartu

Lebih stabil dari ts.net (Tailscale Funnel), **tanpa kartu kredit**, dan **tanpa buka port** di router.
Stack **Laravel 12 / PHP 8.4 / SQLite tidak diubah** — aplikasi tetap jalan di `localhost:8000`.

> Cloudflare Tunnel membuat koneksi **keluar** dari komputermu ke jaringan Cloudflare, lalu Cloudflare memberi alamat publik. Jadi tak perlu IP publik / port-forward.

---

## ⛳ Prasyarat (penting)
- Aplikasi **harus tetap jalan** di `http://localhost:8000` (mis. `php artisan serve --port=8000`) di sebuah **komputer yang menyala 24/7**.
  → Idealnya **PC kantor / mini-PC** yang dibiarkan nyala — **bukan** laptop kerjamu (supaya lepas dari masalah "laptop harus dibuka").
- Akun **Cloudflare gratis** (cukup email, **tanpa kartu kredit**).

Ada **2 opsi**. Pilih sesuai kebutuhan:

| | **Opsi A — Quick Tunnel** | **Opsi B — Tunnel Tetap (disarankan)** |
|---|---|---|
| Akun/domain | ❌ tidak perlu | ✅ akun gratis + 1 domain di Cloudflare |
| Kartu kredit | ❌ tidak | ❌ tidak |
| URL | acak `xxx.trycloudflare.com`, **berubah tiap restart** | **tetap**, mis. `fo.namadomainmu.com` |
| Always-on / auto saat boot | ⚠️ proses biasa (mati bila ditutup) | ✅ Windows **service** (auto saat PC nyala) |
| Untuk | uji coba / sementara | **produksi / dipakai harian** |

---

## ⚡ OPSI A — Quick Tunnel (instan, 3 langkah)

Cocok untuk **langsung bisa diakses sekarang** tanpa akun/domain.

1. **Install cloudflared** (sekali). Buka **PowerShell**, jalankan:
   ```
   winget install --id Cloudflare.cloudflared
   ```
   *(Alternatif manual: unduh `cloudflared-windows-amd64.exe` dari https://github.com/cloudflare/cloudflared/releases/latest → rename jadi `cloudflared.exe`.)*
2. Pastikan aplikasi jalan di `localhost:8000`, lalu jalankan:
   ```
   cloudflared tunnel --url http://localhost:8000
   ```
3. cloudflared mencetak **URL publik** di layar, mis. `https://seasonal-deck-organisms-sf.trycloudflare.com`. **Bagikan URL itu.** Selesai.

> ⚠️ Keterbatasan (resmi): URL **acak & berubah** tiap dijalankan ulang; ini **proses foreground** (kalau jendela ditutup, tunnel mati); batas **200 request bersamaan**; **hanya untuk testing**. Untuk dipakai harian, gunakan **Opsi B**.

*(Saya juga sediakan `deploy/cloudflare/quick-tunnel.bat` — tinggal dobel-klik.)*

---

## 🏆 OPSI B — Tunnel Tetap (dashboard + token, always-on)

URL stabil + jalan otomatis saat PC nyala. Butuh **1 domain di Cloudflare** (akun & domain Free plan, **tanpa kartu**).

### B1. Buat akun + siapkan domain
1. Daftar gratis di **dash.cloudflare.com** (hanya email, tanpa kartu).
2. Tambahkan **1 domain** ke Cloudflare (plan **Free**):
   - **Punya domain** (mis. `javaresidence.com`): klik **Add a domain** → pilih **Free** → Cloudflare memindai DNS lamamu → ganti **nameserver** domain di registrar ke 2 NS Cloudflare. *(Propagasi s/d 24 jam.)*
     > ⚠️ **Hati-hati bila domainnya dipakai layanan lain** (mis. `bo.javaresidence.com`/email): saat onboarding, **pastikan semua DNS record lama (bo, MX/email, dll.) ikut terbawa/terverifikasi** agar layanan itu tak terganggu.
   - **Tak mau utak-atik domain utama?** Beli domain murah khusus (~$1–10/th di Cloudflare Registrar) **atau** pakai **Opsi A** dulu.

### B2. Buat Tunnel (di dashboard — tinggal klik)
3. Buka **dash.cloudflare.com** → **Zero Trust** (menu kiri). *(Aktivasi Zero Trust gratis — bila diminta pilih plan, pilih **Free**.)*
4. **Networks → Tunnels** (di sebagian UI: **Networks → Connectors → Cloudflare Tunnels**) → klik **Create a tunnel**.
5. Pilih **Cloudflared** → **Next**.
6. Beri nama (mis. `fo-localhost-8000`) → **Save tunnel**.
7. Di layar **Install and run a connector**: set **Choose an environment = Windows** → klik **Copy** pada perintahnya (perintah itu **sudah berisi token rahasiamu**, bentuknya `cloudflared.exe service install <TOKEN>`).

### B3. Pasang connector sebagai service Windows (di PC yang selalu nyala)
8. Buka **Command Prompt sebagai Administrator** (klik kanan → *Run as administrator*).
9. **Tempel** perintah dari langkah 7, tekan Enter:
   ```
   cloudflared.exe service install <TOKEN>
   ```
   Ini memasang cloudflared sebagai **Windows service `cloudflared`** yang **start otomatis saat PC nyala** (always-on, bertahan reboot).
   - Cek status: `sc query cloudflared` · Start manual bila perlu: `sc start cloudflared`.
   - *(Bila `cloudflared.exe` belum ada, jalankan dulu `winget install --id Cloudflare.cloudflared`.)*

### B4. Arahkan domain → aplikasi
10. Kembali ke dashboard → **Next** → **Add a public hostname**, isi:
    - **Subdomain:** `fo` (bebas) · **Domain:** pilih domainmu → jadi `fo.namadomainmu.com`
    - **Service → Type:** `HTTP` · **URL:** `localhost:8000`
11. Klik **Save**. Cloudflare otomatis membuat DNS + SSL (HTTPS gratis).
12. Pastikan tunnel berstatus **Healthy** → buka **https://fo.namadomainmu.com** → aplikasi muncul. 🎉

---

## Setelah jalan
- **Matikan ts.net** — tak perlu lagi.
- **Pastikan aplikasi (`php artisan serve`) tetap hidup** di PC itu. Agar otomatis saat boot, jalankan lewat Task Scheduler atau **NSSM** (bisa saya buatkan bila perlu).
- **Token = rahasia.** Siapa pun yang punya token bisa menjalankan tunnel — jangan dibagikan.
- Di Windows, cloudflared **tidak auto-update** — perbarui manual sesekali (`winget upgrade`).

## Kalau ada masalah
- Tunnel **not Healthy** → cek service jalan (`sc query cloudflared`) & aplikasi benar-benar di `localhost:8000`.
- **HTTPS belum aktif** → tunggu beberapa menit (SSL Universal otomatis) & pastikan DNS domain sudah propagasi ke Cloudflare.
- Tempel error-nya ke chat, saya bantu.

## Sumber resmi (terverifikasi)
- Create remote tunnel: https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/get-started/create-remote-tunnel/
- cloudflared sebagai Windows service: https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/do-more-with-tunnels/local-management/as-a-service/windows/
- TryCloudflare (Quick Tunnel): https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/do-more-with-tunnels/trycloudflare/
- Unduh cloudflared: https://developers.cloudflare.com/tunnel/downloads/
