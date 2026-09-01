# Uji manual (FO)

Skrip di sini **bukan** bagian dari suite otomatis. Isinya pemeriksaan yang butuh database
berisi dan dijalankan lewat `tinker`, dipakai saat sebuah perubahan perlu dibuktikan
end-to-end — termasuk yang menyeberang ke database BO.

## Menjalankannya

Container FO (`jerp-app`) di lingkungan pengembangan ini memakai **image**, bukan bind mount,
dan autoloader-nya dibangun `--classmap-authoritative`. Artinya kode terbaru dari repo tidak
otomatis terlihat di sana. Jangan pula menjalankan skrip ini pada database kerja: sebagian
migrasi yang masih tertunda menghapus kolom yang masih dipakai kode di dalam image itu.

Cara yang dipakai: container sekali pakai + database uji sendiri.

```bash
docker run -d --name jerp-verify --network jerp_jerp \
  -v "$PWD/.env:/var/www/html/.env" jerp/app:latest sleep infinity
docker cp ./app      jerp-verify:/var/www/html/
docker cp ./config   jerp-verify:/var/www/html/     # WAJIB — lihat catatan di bawah
docker cp ./database jerp-verify:/var/www/html/
docker cp ./tests    jerp-verify:/var/www/html/
# autoloader image dibangun authoritative — kelas baru tak akan terlihat tanpa ini
docker exec jerp-verify sed -i 's/setClassMapAuthoritative(true)/setClassMapAuthoritative(false)/' \
  /var/www/html/vendor/composer/autoload_real.php
# config yang ter-cache di dalam image menang atas config/ yang baru disalin
docker exec jerp-verify rm -f /var/www/html/bootstrap/cache/config.php
docker exec jerp-mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS jerp_verify; GRANT ALL ON jerp_verify.* TO \"jerp\"@\"%\";"'
docker exec -e DB_DATABASE=jerp_verify jerp-verify php artisan migrate --force
docker exec -e DB_DATABASE=jerp_verify jerp-verify php artisan tinker /var/www/html/tests/Manual/<berkas>.php
docker rm -f jerp-verify      # sekali pakai — jangan ditinggal hidup
```

> Di Git Bash pada Windows, awali `docker` dengan `MSYS_NO_PATHCONV=1` supaya jalur
> `/var/www/...` tidak diterjemahkan menjadi `C:\var\www\...`.

`DB_DATABASE` sebagai variabel lingkungan **menang** atas `.env` (Dotenv tidak menimpa
variabel yang sudah ada), jadi database kerja tidak tersentuh.

### Kenapa `config/` ikut disalin dan cache-nya dihapus

Kedua langkah itu kelihatan berlebihan, tetapi tanpa keduanya uji yang menyeberang ke
database BO gagal dengan pesan yang menyesatkan:

```
SQLSTATE[HY000] [2002] No such file or directory (Connection: ipro_bo, Host: , Port: , …)
```

`Host:` kosong, bukan salah. `config/database.php` di dalam image masih versi lama yang
memakai `env('IPRO_DB_HOST', default)`; padahal `.env` mengirim kunci itu dalam keadaan
**kosong** yang artinya "ikut koneksi utama". `env('X', default)` hanya memakai default bila
kuncinya TIDAK ADA — bila ada tapi kosong, hasilnya `''`, dan koneksi `ipro_bo` jadi tanpa
host. Versi di repo sudah membetulkannya dengan `env('IPRO_DB_HOST') ?: env('DB_HOST', …)`,
jadi `config/` harus ikut disalin — dan `bootstrap/cache/config.php` bawaan image harus
dihapus, sebab config yang ter-cache menang atas berkas yang baru disalin.

## Isinya

| Berkas | Yang diperiksa |
| --- | --- |
| `uji-gmc-cluster.php` | Cluster pada GMC: DITURUNKAN dari unit pada SPR (tidak lagi dipilih di layar) dan dibetulkan bila yang tersimpan berbeda dari SPR-nya, dan Posting BO meneruskan cluster itu sehingga kategori gimmick yang terpakai — beserta akun debitnya — memang milik cluster tersebut. Termasuk penegasan bahwa jalur cadangan kode `FOL-{id}` masih dikenali sama oleh `IproMasterSync` dan `IproApPoster` — itulah yang menjaga BO yang belum dimigrasi tetap bisa diposting. |
| `uji-gmc-massal-supplier.php` | Kolom **Supplier** pada layar Buat GMC Massal. Diperiksa: kolomnya ada dan **wajib** (bintang merahnya lahir dari `required()` yang sama dengan keempat kolom lain — tidak ada penanda terpisah yang bisa lupa dipasang), keempat kolom lama tetap wajib, daftar suppliernya **sama persis** dengan yang dipakai Rincian Komponen GMC tunggal (dua daftar terpisah adalah cara paling mudah keduanya lambat laun menyaring hal berbeda), jumlah & label `KODE — Nama` cocok dengan `bs_suppliers` aktif di BO, dan opsinya **di-memoize** — lima kali render tidak menambah query, sebab halaman ini memang dibangun dengan aturan "tak ada query per baris". Yang terpenting: supplier yang dipilih tersimpan sebagai **rincian komponen**, yakni tempat yang dibaca `postGmcToBo` — kalau tidak, layarnya tetap berkata "N GMC dibuat" sementara hutangnya lahir tanpa supplier. Satu baris tanpa supplier menolak **seluruh** pengiriman, bukan menyimpan sebagian diam-diam. Lima mutasi dibuktikan menggigit. |
| `uji-lokasi-cluster-sinkron.php` | Master Lokasi FO → pilihan Cluster di BO. Membuktikan cerminan itu benar-benar **mengikuti**: kode & nama cluster di BO sama persis dengan yang diketik operator di FO (bukan `FOL-1`), lima kali sinkron tetap satu baris, ganti nama DAN ganti kode menyusul sementara id cluster tidak bergerak (sehingga `bs_gimmick_categories.cluster_id` tak pernah putus), hapus & pulihkan di FO ikut tercermin tanpa menabrak indeks unik, baris warisan `FOL-{id}` diadopsi alih-alih dikembari, Lokasi tanpa kode/nama ditolak alih-alih dikarang jadi "Cluster 7", dan unit yang lokasinya bermasalah tetap tersimpan dengan `cluster_id` kosong. Ditutup dengan pembuktian ujung: daftar cluster hidup di BO sama persis dengan daftar Lokasi hidup di FO.<br><br>Ditambah enam kasus yang lahir dari pemeriksaan lawan, semuanya pernah gagal sungguhan sebelum diperbaiki: cluster yang sudah benar **tidak** tertimpa NULL saat resolusi Lokasinya gagal (sementara unit yang memang tanpa lokasi tetap dikosongkan), kegagalan tidak di-memo sehingga worker berumur panjang tidak terkunci pada jawaban basi, cluster pensiunan tidak dihidupkan kembali oleh Lokasi berkode sama melainkan diberi kode disambiguasi, dua Lokasi yang kodenya bertukar akhirnya konvergen alih-alih saling menabrak selamanya, dan galat integritas selain kunci kembar (mis. FK 1452 akibat `IPRO_PROJECT_ID` salah) muncul apa adanya alih-alih disamarkan jadi tuduhan konkurensi. |

## Catatan saat menulis skrip serupa

1. **Bungkus tulisan FO dalam transaksi lalu rollback.** Yang menyeberang ke database BO
   TIDAK ikut ter-rollback (beda koneksi) — hapus sendiri di akhir, dan periksa sisanya di
   luar transaksi.
2. **Database uji ini kosong.** Bangun sendiri rantai data pendukungnya (company → project →
   lokasi → unit → kontrak) alih-alih bergantung pada seeder yang mungkin tidak dijalankan.
