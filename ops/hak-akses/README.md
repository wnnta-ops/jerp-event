# Hak Akses — penegakan bertahap

Sebelum ini, `canAccessPanel()` hanya memeriksa `is_active`. Artinya **setiap
pengguna aktif bisa membuka semua panel** — FO, BO, System (kelola pengguna &
peran), dan Backup (restore database). 61 akun, 39 peran, satu pintu.

Kosakata izinnya sebenarnya sudah lengkap: **373 izin** dengan penamaan rapi
(`fo.nup.approve`, `system.user.destroy`, …). Yang hilang bukan izinnya,
melainkan penegakannya dan pemetaan peran → izin.

## Sakelar

`AKSES_TEGAKKAN` di `.env`:

| Nilai | Arti |
|---|---|
| `false` (bawaan) | **MODE LAPOR** — semua pemeriksaan berjalan, yang gagal dicatat ke log, **tidak ada yang ditolak** |
| `true` | **MODE TEGAK** — yang gagal ditolak |

Bawaannya sengaja `false`. Saat ini **53 dari 57 pengguna aktif tidak punya satu
izin pun**; menyalakan penegakan sekarang mengunci mereka pada hari kerja
berikutnya, dan satu-satunya pemulihan cepat adalah mematikan seluruh penegakan —
kembali ke titik nol.

Peran `super_admin` dan `sup` **selalu** lolos — keduanya superuser, dan itulah
jalan masuk terakhir bila pemetaan ternyata keliru. `sup` dipakai 7 akun, termasuk
pemilik sistem, jadi jaring pengaman itu memang perlu ada di sana.

## Urutan yang aman

### 1. Lihat keadaan sekarang

```bash
docker exec jerp-app php artisan akses:audit
docker exec jerp-app php artisan akses:audit --peran=sales_staff
```

Tidak mengubah apa pun. Yang perlu diperhatikan: **peran tanpa izin** dan
**pengguna aktif tanpa izin** — keduanya akan terkunci saat mode TEGAK.

### 2. Jalankan beberapa hari dalam MODE LAPOR

Biarkan orang bekerja seperti biasa, lalu kumpulkan apa yang *akan* ditolak:

```bash
docker exec jerp-app sh -c 'grep -a "\[akses\] AKAN DITOLAK" storage/logs/laravel.log' \
  | sed 's/.*"email":"\([^"]*\)".*"izin":"\([^"]*\)".*/\1 \2/' | sort | uniq -c | sort -rn
```

Keluarannya adalah daftar izin yang benar-benar dibutuhkan orang untuk bekerja —
jauh lebih dapat dipercaya daripada menebak dari nama peran.

### 3. Terapkan pemetaan peran

```bash
docker exec jerp-app php artisan akses:petakan            # usulan saja
docker exec jerp-app php artisan akses:petakan --terapkan # simpan
```

Usulannya diturunkan dari **nama peran**, jadi ia tebakan awal — bukan kebenaran.
Sandingkan dengan hasil langkah 2 sebelum diterapkan.

**12 peran tidak punya usulan** karena namanya tidak mengisyaratkan pekerjaannya:
`art`, `default`, `estate`, `koor`, `mg`, `po3`, `pos1`–`pos5`.
Peran seperti itu **dibiarkan apa adanya**, bukan dikosongkan.

### 4. Baru nyalakan penegakan

```bash
AKSES_TEGAKKAN=true
```
lalu `docker restart jerp-app`.

Kalau ada yang terkunci: kembalikan ke `false`, restart, dan sistem pulih seketika.

## Cakupan saat ini

**21 dari 92 resource** sudah dipetakan ke izin (`config/akses.php`). Sisanya
**dibiarkan lolos dengan sengaja** dan dilaporkan `akses:audit` sebagai belum
berpenjaga — menutup semuanya sekaligus, pada sistem yang izinnya belum dipetakan,
hampir pasti mematikan menu yang belum sempat ditinjau.

Menambah cakupan = menambah baris di `config/akses.php` lalu memasang trait
`MematuhiIzin` pada resource-nya. Tidak perlu menyunting logika.

## Yang TIDAK dikerjakan, dan alasannya

**Policy per-record belum dibuat.** Policy Laravel hanya berguna bila aturannya
bergantung pada isi record — misalnya "sales hanya boleh melihat pendaftaran
miliknya sendiri". Selama aturannya cukup "boleh/tidak boleh per menu", izin sudah
menyelesaikannya dan policy hanya menambah berkas tanpa menambah keamanan.

Bila nanti aturan seperti itu memang dibutuhkan, barulah policy dibuat — untuk
model yang bersangkutan saja.
