<?php

declare(strict_types=1);

/**
 * Uji: penulis & pembaca XLSX seadanya (App\Support\Xlsx).
 *
 * Berdiri sendiri tanpa database. Yang paling penting di sini kasus SEL KOSONG DI TENGAH:
 * Excel menghilangkan sel kosong dari XML-nya, dan pembaca yang menyusun baris dari urutan
 * kemunculan akan menggeser seluruh kolom di kanannya — nilai bulan terbaca pada bulan yang
 * salah, tanpa satu pun galat. Itu persis yang membuat angka anggaran meleset diam-diam.
 *
 * Jalankan: sudo docker exec ipro-fo-app php tests/Manual/uji-xlsx.php
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Support\Xlsx;

$lulus = 0;
$gagal = 0;
$cek = function (string $label, $dapat, $harap) use (&$lulus, &$gagal) {
    $ok = $dapat === $harap;
    $ok ? $lulus++ : $gagal++;
    printf("  %-5s %-44s %-30s (harap %s)\n", $ok ? 'OK' : 'GAGAL', $label,
        is_scalar($dapat) || $dapat === null ? var_export($dapat, true) : json_encode($dapat),
        is_scalar($harap) || $harap === null ? var_export($harap, true) : json_encode($harap));
};

$tmp = sys_get_temp_dir().'/uji-xlsx-'.getmypid().'.xlsx';

echo "\n=== Tulis lalu baca kembali ===\n";
$header = ['KodeAccount', 'NamaAccount', 'CostCenter', 'Jan', 'Feb'];
$baris = [
    ['50302020219', 'BIAYA PERLENGKAPAN KANTOR', 'CC-01', 1000000, 2000000],
    ['50302020220', 'BIAYA LISTRIK', 'CC-02', 500000, 750000],
];
file_put_contents($tmp, Xlsx::tulis($header, $baris, 'Budget'));
$cek('berkas terbentuk', file_exists($tmp) && filesize($tmp) > 0, true);

$hasil = Xlsx::baca($tmp);
$cek('3 baris (header + 2 data)', count($hasil), 3);
$cek('header utuh', $hasil[0], $header);
$cek('kode account', $hasil[1][0], '50302020219');
$cek('cost center', $hasil[1][2], 'CC-01');
$cek('angka terbaca sbg teks angka', $hasil[1][3], '1000000');
$cek('baris kedua', $hasil[2][2], 'CC-02');

echo "\n=== SEL KOSONG DI TENGAH tidak menggeser kolom ===\n";
// Kolom CostCenter sengaja kosong. Pembaca yang memakai urutan kemunculan akan membaca
// nilai Jan sebagai CostCenter, dan seluruh bulan bergeser satu kolom.
file_put_contents($tmp, Xlsx::tulis($header, [
    ['50302020219', 'BIAYA PERLENGKAPAN KANTOR', '', 1000000, 2000000],
], 'Budget'));
$h = Xlsx::baca($tmp);
$cek('kolom CostCenter tetap kosong', $h[1][2], '');
$cek('Jan TIDAK bergeser ke CostCenter', $h[1][3], '1000000');
$cek('Feb tetap di tempatnya', $h[1][4], '2000000');

echo "\n=== Teks ber-karakter khusus ===\n";
file_put_contents($tmp, Xlsx::tulis(['A'], [['PT "Maju" & Co <Jaya>']], 'X'));
$h = Xlsx::baca($tmp);
$cek('tanda kutip & ampersand utuh', $h[1][0], 'PT "Maju" & Co <Jaya>');

echo "\n=== Kolom melewati Z ===\n";
$lebar = [];
for ($i = 1; $i <= 30; $i++) {
    $lebar[] = 'K'.$i;
}
file_put_contents($tmp, Xlsx::tulis($lebar, [], 'X'));
$h = Xlsx::baca($tmp);
$cek('30 kolom terbaca', count($h[0]), 30);
$cek('kolom ke-27 (AA) benar', $h[0][26], 'K27');

echo "\n=== Berkas rusak ditolak dgn pesan ===\n";
file_put_contents($tmp, 'ini bukan xlsx');
try {
    Xlsx::baca($tmp);
    $cek('ditolak', 'TIDAK DITOLAK', 'ditolak');
} catch (RuntimeException $e) {
    $cek('pesan menyebut .xlsx', str_contains($e->getMessage(), '.xlsx'), true);
}

@unlink($tmp);
printf("\n  LULUS %d · GAGAL %d\n", $lulus, $gagal);
exit($gagal > 0 ? 1 : 0);
