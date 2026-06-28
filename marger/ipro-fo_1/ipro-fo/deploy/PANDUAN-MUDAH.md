# 🟢 PANDUAN MUDAH — Deploy Java Core System (klik & 1× tempel)

Hampir tanpa ngetik. Hanya butuh **2 alat**:
1. **Browser** (untuk Oracle Cloud Console)
2. **WinSCP** (gratis — untuk upload file *drag & drop* + jalankan 1 perintah). Unduh: **winscp.net** → klik **Download WinSCP** → install (klik Next sampai selesai).

Hasil akhir: aplikasi online 24/7 di **https://domainmu**, HTTPS otomatis, **tanpa laptop & tanpa ts.net**.

> Catatan: nama tombol di Oracle bisa sedikit berbeda, tapi alurnya sama.

---

## BAGIAN 1 — Buat server (klik di browser)

1. Buka **cloud.oracle.com** → **Sign in** (atau **Start for free** untuk daftar; perlu kartu untuk verifikasi, **resource Always Free tidak ditagih**).
2. Klik menu **☰** (kiri atas) → **Compute** → **Instances**.
3. Klik tombol **Create instance**.
4. **Image and shape**:
   - Klik **Edit** → **Change image** → pilih **Canonical Ubuntu** → versi **24.04** → **Select image**.
   - Klik **Change shape** → tab **Ampere** → pilih **VM.Standard.A1.Flex** → set **1 OCPU / 6 GB** → **Select shape**.
     *(Kalau muncul "out of capacity", ulangi nanti / pilih **Specialty and previous generation → VM.Standard.E2.1.Micro**.)*
5. **Add SSH keys**: pilih **Generate a key pair for me** → klik **Save private key** (simpan file `.key`, ingat lokasinya).
6. Klik **Create**. Tunggu status jadi **Running** (hijau).
7. **Catat "Public IP address"** yang tampil. (mis. `152.x.x.x`)

## BAGIAN 2 — Buka port 80 & 443 (klik)

1. Masih di halaman instance, scroll ke **Primary VNIC** → klik nama **Subnet**-nya.
2. Klik **Security Lists** → klik **Default Security List for ...**.
3. Klik **Add Ingress Rules**, isi lalu **Add**:
   - **Source CIDR:** `0.0.0.0/0`
   - **IP Protocol:** **TCP**
   - **Destination Port Range:** `80`
4. Klik **Add Ingress Rules** lagi, sama tapi **Destination Port Range:** `443`.

## BAGIAN 3 — Domain gratis (klik)

1. Buka **duckdns.org** → **sign in** (pakai Google/GitHub).
2. Di kotak isian ketik nama, mis. `javacore-mup` → klik **add domain**.
3. Pada baris domain itu, isi **current ip** = **Public IP** server (Bagian 1) → klik **update ip**.
4. Domainmu jadi: **`javacore-mup.duckdns.org`** (ingat ini).

## BAGIAN 4 — Upload aplikasi (drag & drop di WinSCP)

1. Buka **WinSCP** → muncul **Login**. Isi:
   - **File protocol:** SFTP
   - **Host name:** Public IP server
   - **User name:** `ubuntu`
2. Klik **Advanced…** → kiri pilih **SSH → Authentication** → di **Private key file** klik **…** → pilih file **`.key`** dari Bagian 1.
   *(WinSCP menawarkan konversi ke format .ppk → klik **OK** / **Yes**, simpan.)* → klik **OK**.
3. Klik **Login**. Jika muncul peringatan host key → **Yes**.
4. Panel **kiri = komputermu**, **kanan = server**.
   - Panel kanan sudah di `/home/ubuntu`. Klik kanan area kosong → **New → Directory** → ketik **`javacore`** → OK. Lalu **double-click** masuk ke folder `javacore`.
   - Panel kiri: masuk ke folder **`...\Documents\Claude\FO\ipro-fo`**.
5. Di panel kiri: tekan **Ctrl+A** (pilih semua), lalu **Ctrl+klik** pada **`vendor`** dan **`node_modules`** untuk **membatalkan pilih keduanya** (jangan diupload — berat & dibuat ulang otomatis).
6. **Drag** item terpilih ke panel kanan (folder `javacore`). Klik **OK** bila ada konfirmasi. Tunggu sampai selesai.
   - ⚠️ Pastikan folder **`database`** dan file **`.env`** ikut terupload (itu data & kunci aplikasimu). Bila `.env` tak terlihat, tekan **Ctrl+Alt+H** di WinSCP untuk menampilkan file tersembunyi, lalu upload.

## BAGIAN 5 — Jalankan 1 perintah (tempel sekali)

1. Di WinSCP, menu atas: **Commands → Open Terminal**.
2. **Tempel** perintah berikut (ganti domain dengan punyamu), tekan **Enter**:
   ```
   cd ~/javacore && bash deploy/bootstrap.sh javacore-mup.duckdns.org
   ```
3. Tunggu (5–10 menit, sekali ini saja). Akan jalan otomatis: install PHP/Caddy/Node → setting → build → migrate → HTTPS → backup.
4. Selesai bila muncul: **`SELESAI (OK)  ...  https://domainmu`**.

## BAGIAN 6 — Cek & beres

1. Buka **https://domainmu** di browser → halaman **login Java Core System** muncul. 🎉
   *(Bila 30 detik pertama belum HTTPS, refresh — Caddy sedang ambil sertifikat.)*
2. Login seperti biasa. Server ini **nyala 24/7** — tak bergantung laptop.
3. **Matikan ts.net**: di laptop tidak perlu lagi `tailscale funnel`. Selesai.

---

## 🔄 Update aplikasi nanti (kalau ada perubahan kode)
1. WinSCP: drag file/folder yang berubah ke `/var/www/javacore` (timpa).
2. WinSCP **Commands → Open Terminal**, tempel:
   ```
   cd /var/www/javacore && bash deploy/deploy.sh
   ```

## ❓ Kalau ada error
Tempel teks error dari Terminal WinSCP ke chat — saya bantu beresin. Masalah paling umum:
- **Tidak bisa buka https** → cek Bagian 2 (port 80/443 di OCI) sudah benar.
- **Sertifikat HTTPS gagal** → pastikan domain (DuckDNS) sudah menunjuk ke IP server (Bagian 3) dan port 80 terbuka.
