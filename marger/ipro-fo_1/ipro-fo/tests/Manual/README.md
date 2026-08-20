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
| `uji-realisasi-bon-cost-center.php` | Rantai penuh Cost Center pada Realisasi Bon Sementara: tersimpan di `bs_journal_lines.cost_center_id`, lalu terbaca `BudgetRealizationService` sebagai realisasi pada (akun, cost center) yang tepat — dan **tidak** menambah cost center lain. Termasuk penolakan server saat cost center dikosongkan atau diisi id yang tidak ada di master, beserta penegasan bahwa bonnya tidak berubah status dan tidak ada realisasi anggaran yang terbentuk dari percobaan yang ditolak. |
| `uji-coa-level-induk.php` | Level & Induk pada Tambah/Edit Akun: level dipilih (bukan diterka panjang kode), induk wajib dan harus tepat satu tingkat di atas, kode harus sepanjang levelnya dan melanjutkan kode induk, `parent_id` tersimpan, dan level akun yang sudah beranak tidak bisa dipindah. |
| `uji-gimmick-cakupan.php` | Cakupan Rincian Biaya pada Setup Kategori Gimmick: kolom hilang dari daftar belum-tertaut begitu ditautkan (walau sinkronnya mati), `aktif` tetap membedakan "sudah dipegang" dari "sudah akan terisi", dan penanda sinkron per baris. |
| `uji-impor-supplier.php` | Hasil `supplier:impor-legacy`: 757 supplier cocok baris-per-baris dengan berkas sumber di 11 kolom, koreksi NPWP yang nyasar ke kolom Nama Bisnis benar-benar terjadi, dan kode berikutnya meneruskan SP.00764 → SP.00765 (dibandingkan sebagai angka, sehingga kode berangka kecil maupun kode gaya lama SUP- tidak menggeser deret). |
| `uji-cetak-pengajuan-kasbon.php` | Pemilihan formulir Cetak Pengajuan bon sementara berdasar nominal: ≥ Rp 1 juta memakai formulir KAS BON, di bawahnya memakai BPP + Memo. Termasuk batas persisnya (Rp 999.999 vs Rp 1.000.000) dan terbilang yang tercetak. |
| `uji-penyelesaian-bon.cjs` | Penentuan cara penyelesaian bon sementara (Refund / Reimburse / Match) langsung dari binding komponen Vue-nya: realisasi < pengajuan → Refund, > → Reimburse, sama → Match. |
| `uji-cetak-realisasi-kasbon.php` | Formulir Cetak Realisasi mengikuti cara penyelesaiannya — Refund & Match memakai formulir REFUND, Reimburse memakai formulir REIMBURSE — beserta baris "No. Cek / BG" dan hilangnya tombol Cetak Pengeluaran setelah bon direalisasi. |
| `uji-impor-kategori-invoice.php` | Impor Kategori Invoice: kolom format, penolakan menyeluruh saat ada baris cacat, dan pencocokan lewat kode sehingga impor ulang tidak menggandakan kategori. |
| `uji-hstack-label.cjs` | Penempatan angka pada batang bertumpuk (`VizHStack`, kartu Overview Aset Tetap): setiap angka yang ditulis DI DALAM segmen terbukti muat — angka di dalam berwarna putih, jadi yang melewati tepi segmen hilang ke latar kartu, bukan sekadar sesak — yang tidak muat pindah ke luar ujung batang alih-alih dihapus, angka luar tidak melewati tepi kanan bidang gambar, dan talang kanan hanya melebar bila memang ada angka di luar. Jalankan dengan `node tests/Manual/uji-hstack-label.cjs .` |
| `uji-corong-kpr.cjs` | Geometri corong Inisiasi & Proses KPR langsung dari `VizFunnel.vue`: lebar batang sebanding dengan jumlahnya, pita penghubung bertepi **sama persis** dengan batang yang dihubungkannya (itulah yang membuatnya terbaca satu aliran), selisih antar tahap ditulis sebagai jumlah — bukan persen — nama tahap tidak dipenggal, serta keadaan tepi: tahap yang justru bertambah, semua nol, nilai bukan bilangan, dan tahap sangat kecil yang tetap harus terlihat. Jalankan dengan `node tests/Manual/uji-corong-kpr.cjs .` |
| `uji-laporan-gl-export.php` | Export Excel + periode pada laporan GL: Neraca menghormati "Per Tanggal" (selisih antar tanggal persis sebesar transaksi di antaranya), Laba Rugi & LR vs Budget menghormati rentang termasuk pembetulan rentang terbalik, budget diprorata ke bulan yang tercakup, Laporan GL Lanjutan berhenti pada tanggal yang dipilih untuk mode neraca dan memakai rentang setara untuk mode laba rugi, serta kelima berkas .xlsx benar-benar bisa dibuka dan angkanya sama dengan yang tampil di layar. |
| `uji-jurnal-anggaran-kolom.php` | Detail Transaksi Jurnal: keterangan Over/Under/Non Budget per jurnal (hanya anggaran disetujui, termasuk anggaran lintas cost center), penegasan bahwa gerbang `BudgetGuardService` memang MENOLAK input pada akun yang sudah over dan MENERIMA yang masih under, saringan kolom Anggaran untuk ketiga nilainya, serta saringan kolom lain (No. Jurnal, Tanggal, Akun, Keterangan Baris, Debit, Baris) beserta rekap per status yang ikut tersaring. |
| `uji-gimmick-cluster.php` | Cluster pada Kategori Gimmick: keunikan kode kini per (tenant, cluster, kode) sehingga satu jenis biaya boleh ada di tiap cluster dengan akun sendiri, pencarian kategori saat Posting BO (kategori clusternya menang, kategori tanpa cluster jadi cadangan), dan cakupan Rincian Biaya yang dihitung per cluster sehingga satu kategori tidak membuat kolom terlihat aman padahal cluster lain terlewat. |
| `uji-impor-coa.php` | Impor Chart of Account dari lampiran "COA Aktiva": 204 akun cocok persis (nama, level, tipe, saldo normal), rantai `parent_id` utuh dari level 1 sampai 6, angka format `180` tidak pernah tersimpan sebagai tipe, hanya level 6 yang bisa dijurnal, berkas cacat ditolak seluruhnya, impor ulang tidak menggandakan, dan berkas "Unduh Format" bisa diunggah balik apa adanya. |

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
