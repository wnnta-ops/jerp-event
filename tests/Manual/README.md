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
docker cp ./app jerp-verify:/var/www/html/
docker cp ./database jerp-verify:/var/www/html/
docker cp ./tests/Manual jerp-verify:/var/www/html/tests/
# autoloader image dibangun authoritative — kelas baru tak akan terlihat tanpa ini
docker exec jerp-verify sed -i 's/setClassMapAuthoritative(true)/setClassMapAuthoritative(false)/' \
  /var/www/html/vendor/composer/autoload_real.php
docker exec jerp-mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS jerp_verify; GRANT ALL ON jerp_verify.* TO \"jerp\"@\"%\";"'
docker exec -e DB_DATABASE=jerp_verify jerp-verify php artisan migrate --force
docker exec -e DB_DATABASE=jerp_verify jerp-verify php artisan tinker /var/www/html/tests/Manual/<berkas>.php
```

> Di Git Bash pada Windows, awali `docker` dengan `MSYS_NO_PATHCONV=1` supaya jalur
> `/var/www/...` tidak diterjemahkan menjadi `C:\var\www\...`.

`DB_DATABASE` sebagai variabel lingkungan **menang** atas `.env` (Dotenv tidak menimpa
variabel yang sudah ada), jadi database kerja tidak tersentuh.

## Isinya

| Berkas | Yang diperiksa |
| --- | --- |
| `uji-gmc-cluster.php` | Cluster pada GMC: DITURUNKAN dari unit pada SPR (tidak lagi dipilih di layar) dan dibetulkan bila yang tersimpan berbeda dari SPR-nya, dan Posting BO meneruskan cluster itu sehingga kategori gimmick yang terpakai — beserta akun debitnya — memang milik cluster tersebut. Termasuk penegasan bahwa konvensi kode cluster `FOL-{id}` sama antara `IproMasterSync` dan `IproApPoster`. |

## Catatan saat menulis skrip serupa

1. **Bungkus tulisan FO dalam transaksi lalu rollback.** Yang menyeberang ke database BO
   TIDAK ikut ter-rollback (beda koneksi) — hapus sendiri di akhir, dan periksa sisanya di
   luar transaksi.
2. **Database uji ini kosong.** Bangun sendiri rantai data pendukungnya (company → project →
   lokasi → unit → kontrak) alih-alih bergantung pada seeder yang mungkin tidak dijalankan.
