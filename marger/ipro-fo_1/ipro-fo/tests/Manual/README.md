# Uji manual (tinker)

Skrip pemeriksaan yang **membutuhkan database sungguhan** dan karena itu tidak ikut
dijalankan oleh suite `phpunit.xml` (yang hanya memungut `tests/Unit` dan `tests/Feature`
berakhiran `Test.php`).

Semuanya membungkus dirinya dalam `DB::beginTransaction()` dan **selalu** `DB::rollBack()`
di blok `finally`, jadi menjalankannya tidak meninggalkan satu baris pun di database —
termasuk saat gagal di tengah jalan. Aman dijalankan terhadap DB dev.

## Cara menjalankan

```bash
docker cp tests/Manual/uji-sip-penomoran-departemen.php ipro-fo-app:/tmp/uji.php && docker exec ipro-fo-app php artisan tinker /tmp/uji.php
```

Keluarannya berupa daftar `OK` / `GAGAL` dan diakhiri ringkasan `n lolos, n gagal`.
Exit code 0 bila semuanya lolos.

> Di Git Bash pada Windows, awali `docker` dengan `MSYS_NO_PATHCONV=1` supaya jalur
> `/tmp/...` tidak diterjemahkan menjadi `C:\tmp\...`.

## Isinya

| Berkas | Yang diperiksa |
| --- | --- |
| `uji-sip-penomoran-departemen.php` | Penomoran SIP satu-nomor (`NNN/FIN/MITRA-SI/{romawi}/{tahun}`): pengambilan urutan dari `MAX(print_seq)`, urutan lanjut dalam satu tahun, mulai ulang saat ganti tahun, dan SIP terhapus yang **tidak** membebaskan nomornya. Lalu tiga jalur pencarian departemen per baris cetakan: bon sementara → `department_id`-nya, invoice → departemen pembuat invoice, dan cadangan → departemen pembuat SIP. |
| `uji-jurnal-rekap-periode-closed.php` | Penanda `period_closed` per baris pada Detail Transaksi Jurnal, dan rekap jumlah per status. Termasuk penegasan bahwa keempat angkanya **sama persis** dengan `totals` milik General Function untuk tahun yang sama, bahwa rekap mengabaikan filter Status tapi menghormati filter tanggal & pencarian. |

## Catatan saat menulis skrip serupa

Dua jebakan yang sudah memakan waktu dan sebaiknya tidak diulang:

1. **Relasi yang dimuat harus disamakan dengan controllernya.** Strict lazy loading aktif di
   aplikasi ini. Memuat lebih sedikit relasi daripada controller akan melaporkan
   `LazyLoadingViolationException` yang tidak pernah terjadi di produksi; memuat lebih banyak
   akan menutupi pelanggaran yang justru nyata.
2. **`app()->instance('request', $req)` menimpa user-resolver.** Laravel memasang rebinding
   pada `request` yang mengganti resolver dengan resolver guard — kosong di lingkungan tinker,
   sehingga `$request->user()` menjadi `null` dan controller memulangkan 403. Panggil
   `setUserResolver()` **sesudah** penukaran instance, bukan sebelum.
