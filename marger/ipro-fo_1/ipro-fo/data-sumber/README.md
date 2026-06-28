# 📥 DATA SUMBER — Folder untuk modul/baru & data masukan

Folder ini adalah **tempat menaruh semua data sumber** untuk modul baru atau
penambahan data ke Java Core System. Taruh file di sini, beri tahu modulnya,
lalu data akan diproses (migration + seeder/importer) ke dalam sistem.

## Lokasi
```
C:\Users\SPV ACC MUP\Documents\Claude\FO\ipro-fo\        ← ROOT software (acuan)
└── data-sumber\                                          ← TARUH FILE DI SINI
    ├── README.md                (panduan ini)
    ├── 01-master\               (data master/referensi: bank, wilayah, kategori, dll)
    ├── 02-modul-baru\           (data + spesifikasi modul yang akan ditambahkan)
    └── 03-contoh-transaksi\     (contoh transaksi/sampel untuk uji coba)
```

## Format file yang didukung
- **Excel / CSV** (`.xlsx`, `.csv`) — paling umum untuk tabel data.
- **SQL** (`.sql`) — dump tabel (seperti dataset wilayah).
- **JSON** (`.json`) — data terstruktur / konfigurasi.
- **PDF / gambar / DOCX** — untuk *spesifikasi* modul (contoh form, alur, aturan).

## Cara pakai (alur menambah modul baru)
1. Buat subfolder bernama modulnya, mis. `02-modul-baru\Pajak-PBB\`.
2. Masukkan:
   - file data (`.xlsx`/`.csv`/`.sql`), dan
   - 1 file `SPEC.md` atau screenshot/PDF yang menjelaskan: kolom, aturan, dan
     tampilan yang diinginkan.
3. Beri tahu: "modul X, datanya di `data-sumber\...`".
4. Akan dibuatkan: **migration** (tabel), **model**, **controller**, **halaman Vue**,
   **route**, **menu sidebar**, **permission**, dan **importer/seeder** yang membaca
   file dari folder ini.

## Catatan teknis
- Importer artisan membaca file lewat parameter `--file=` (path absolut), contoh
  yang sudah ada:
  - `php artisan customers:import --file="...\data-sumber\01-master\customers.csv"`
  - `php artisan regions:import --file="...\data-sumber\01-master\wilayah.sql"`
- File besar **tidak** ikut ter-commit git (lihat `.gitignore` folder ini) supaya
  repositori tetap ringan; datanya tetap masuk database.
