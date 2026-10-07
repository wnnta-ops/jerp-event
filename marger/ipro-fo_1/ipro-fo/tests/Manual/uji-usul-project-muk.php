<?php

declare(strict_types=1);

/**
 * Uji: penguraian nama "proyek" MUK menjadi nomor cluster + tahap.
 *
 * Inilah yang menentukan usulan baris Setup Project. Salah urai berarti biaya konstruksi
 * diusulkan menempel ke cluster atau tahap yang keliru — dan kalau operator menyetujuinya
 * sambil lalu, tidak ada galat apa pun yang menandainya.
 *
 * Separuh kasus di sini justru menguji sistem TIDAK menebak: paket yang memang tidak punya
 * tahap (infra satu cluster penuh) harus memulangkan tahap null, bukan dipaksa ke tahap 1.
 *
 * Nama-nama di bawah SELURUHNYA nyata, diambil dari 94 proyek yang tertarik 1 Okt 2026 —
 * termasuk salah ketiknya (JKRC, CLUTER), yang memang ada di data mereka.
 *
 * Tidak butuh database. Jalankan:
 *   sudo docker exec ipro-fo-app php tests/Manual/uji-usul-project-muk.php
 */

use App\Domain\BusinessSupport\Services\UsulProjectMuk;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$svc = new UsulProjectMuk;

$kasus = [
    // nama proyek MUK,                                        cluster, tahap
    ['JAVA RESIDENCE KRIAN CLUSTER 1 TAHAP II',                1,  2],   // tahap Romawi
    ['JAVA RESIDENCE KRIAN CLUSTER 2 TAHAP 2',                 2,  2],
    ['PROYEK HOUSING CLUSTER 3 TAHAP 4 BATCH I',               3,  4],   // "BATCH I" bukan tahap
    ['INFRA CLUSTER 3 TAHAP 4',                                3,  4],
    ['KANOPI CARPORT CLUSTER 3 TAHAP 3 & 4',                   3,  3],   // dua tahap: ambil yang pertama
    ['INFRASTRUKTUR CLUSTER 2 PHASE 2',                        2,  2],   // "PHASE" = tahap
    ['PROYEK HOUSING JRKC3 TAHAP 1',                           3,  1],   // singkatan tanpa spasi
    ['PROYEK HOUSING JRSC 5 TAHAP 1 EXTD',                     5,  1],
    ['KANOPI HOUSING JKRC 2 TAHAP 1',                          2,  1],   // salah ketik JKRC
    ['SI JOGING TRACK CLUTER 5',                               5,  null], // salah ketik CLUTER
    ['PEKERJAAN PERBAIKAN DAN KOMPLAI CLUSTER1',               1,  null], // tanpa spasi

    // Cluster saja — TAHAP HARUS null. Pekerjaan ini mencakup satu cluster penuh; memaksakan
    // tahap berarti mengarang, dan biayanya menempel ke tahap yang salah.
    ['INFRA JAVA RESIDENCE KRIAN CLUSTER 2',                   2,  null],
    ['SITE INSTRUCTION CLUSTER 1 INFRA',                       1,  null],
    ['MUSHOLLA CLUSTER 3',                                     3,  null],
    ['PERSIAPAN JAVA RESIDENCE SUKODONO CLUSTER 5',            5,  null],

    // Tidak punya cluster sama sekali — fasilitas umum & bangunan komersial. KEDUANYA null:
    // menebak cluster untuk ini akan membebankan biaya Java Center ke perumahan.
    ['SI JAVA CENTER',                                         null, null],
    ['MUSHOLLAH JAVA CENTER',                                  null, null],
    ['NURSERY JAVA CENTER',                                    null, null],
    ['PEKERJAAN RENOVASI INDOMARET BOULEVARD KAHURIPAN',       null, null],
    ['SITE INSTRUCTION OFFICE MUP',                            null, null],
    ['PENAMBAHAN STRUKTUR AVENTIS RAYA NO. 11',                null, null],
];

$lulus = $gagal = 0;

foreach ($kasus as [$nama, $cHarap, $tHarap]) {
    [$c, $t] = $svc->urai($nama);
    $ok = $c === $cHarap && $t === $tHarap;
    $ok ? $lulus++ : $gagal++;

    printf(
        "  %-5s %-52s -> cluster=%-4s tahap=%-4s (harap %s / %s)\n",
        $ok ? 'OK' : 'GAGAL',
        mb_strimwidth($nama, 0, 52, ''),
        $c ?? '-',
        $t ?? '-',
        $cHarap ?? '-',
        $tHarap ?? '-'
    );
}

printf("\n  LULUS %d · GAGAL %d\n", $lulus, $gagal);

exit($gagal > 0 ? 1 : 0);
