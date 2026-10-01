<?php

declare(strict_types=1);

/**
 * Uji: penguraian nama lokasi MUK menjadi blok + nomor rumah.
 *
 * MUK mengirim kolom `blok` dan `no_rumah` dalam keadaan KOSONG (diperiksa 1 Okt 2026),
 * padahal keduanya ada di dalam `nama`: "CENDANA JRKC2 VI No. 15". BsMukLokasi menguraikannya
 * untuk MENGUSULKAN pasangan unit — operator yang memutuskan.
 *
 * YANG PALING MAHAL BILA SALAH bukan tebakan yang meleset, melainkan tebakan atas nama yang
 * BUKAN RUMAH. "PEK. SI INFRA CLUSTER 5" yang terbaca sebagai blok C nomor 5 akan menyodorkan
 * pasangan unit untuk pekerjaan infrastruktur — dan kalau operator menyetujuinya sambil lalu,
 * biaya infrastruktur satu cluster menempel pada satu rumah. Karena itu separuh kasus di sini
 * justru menguji bahwa sistem DIAM.
 *
 * Memanggil method ASLI (BsMukLokasi::tebakBlokNomor), bukan menyalin regex-nya: salinan bisa
 * lulus sementara kode yang dipakai aplikasi sudah berbeda.
 *
 * Tidak butuh database. Jalankan:
 *   sudo docker exec ipro-fo-app php tests/Manual/uji-tebak-blok-nomor.php
 */

use App\Domain\BusinessSupport\Models\BsMukLokasi;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$kasus = [
    // nama,                                    blok harapan, nomor harapan
    ['CENDANA JRKC2 VI No. 15',                 'VI',  '15'],   // contoh nyata dari API
    ['A1 — CACA JRWC6 I NO.1',                  'I',   '1'],    // contoh dari dokumentasi MUK
    ['CENDANA JRKC2 XIX No. 23A',               'XIX', '23A'],  // nomor berhuruf
    ['CENDANA JRKC2 VI-15',                     'VI',  '15'],   // pemisah tanda hubung
    ['CENDANA JRKC2 VI 15',                     'VI',  '15'],   // tanpa kata "No"
    ['INFRA CLUSTER 5 — CLUSTER 5 SUKODONO',    null,  null],   // bukan rumah: HARUS diam
    ['PEK. SI INFRA CLUSTER 5',                 null,  null],   // bukan rumah
    ['MUSHOLLA CLUSTER 3',                      null,  null],   // bukan rumah
];

$lulus = $gagal = 0;

foreach ($kasus as [$nama, $blokHarap, $noHarap]) {
    $h = (new BsMukLokasi(['nama' => $nama]))->tebakBlokNomor();
    $ok = $h['blok'] === $blokHarap && $h['no_rumah'] === $noHarap;
    $ok ? $lulus++ : $gagal++;

    printf(
        "  %-5s %-42s -> blok=%-5s no=%-5s (harap %s / %s)\n",
        $ok ? 'OK' : 'GAGAL',
        $nama,
        $h['blok'] ?? '-',
        $h['no_rumah'] ?? '-',
        $blokHarap ?? '-',
        $noHarap ?? '-'
    );
}

// Kolom dari MUK selalu menang bila terisi — penguraian nama hanya cadangan. Kalau kelak MUK
// mengisi blok/no_rumah, tebakan tidak boleh menimpanya.
$asli = (new BsMukLokasi(['nama' => 'CENDANA JRKC2 VI No. 15', 'blok' => 'XX', 'no_rumah' => '99']))->tebakBlokNomor();
$ok = $asli['blok'] === 'XX' && $asli['no_rumah'] === '99';
$ok ? $lulus++ : $gagal++;
printf("  %-5s kolom asli MUK menang atas tebakan nama -> blok=%s no=%s (harap XX / 99)\n", $ok ? 'OK' : 'GAGAL', $asli['blok'], $asli['no_rumah']);

printf("\n  LULUS %d · GAGAL %d\n", $lulus, $gagal);

exit($gagal > 0 ? 1 : 0);
