<?php

declare(strict_types=1);

return [
    /*
     * Nama BASIS DATA aplikasi Front Office, untuk kolom yang datanya memang hanya ada di sana
     * (mis. Lokasi & Tahap unit pada Master Data KPR).
     *
     * Back Office dan Front Office berjalan di SERVER MySQL yang sama tetapi pada basis data
     * berbeda, sehingga tabelnya bisa dibaca dengan menyebut nama basis datanya. Namanya
     * dikonfigurasi, bukan ditulis mati di kueri: nama itu berbeda antar lingkungan, dan kueri
     * yang memaku nama akan gagal di lingkungan mana pun yang tidak memakai nama yang sama.
     *
     * Dikosongkan → pembacaan lintas basis data DIMATIKAN sepenuhnya dan kolom yang
     * membutuhkannya tampil kosong. Laporannya tetap hidup.
     */
    'fo_database' => env('FO_DATABASE', 'jerp'),
];
