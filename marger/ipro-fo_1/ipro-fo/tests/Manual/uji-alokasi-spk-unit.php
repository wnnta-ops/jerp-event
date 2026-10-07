<?php

declare(strict_types=1);

/**
 * Uji: pembagian nilai SPK ke unit menurut luas (Lampiran 1).
 *
 * Yang diuji ARITMETIKANYA, berdiri sendiri tanpa database — rumus dan pembulatannya ditiru
 * persis dari AlokasiSpkUnit. Yang tidak bisa diuji di sini (pengambilan pasangan unit,
 * resolusi komponen COGS) butuh database berisi dan diperiksa langsung di layar.
 *
 * YANG PALING MAHAL BILA SALAH bukan selisih satu rupiah pada satu unit, melainkan JUMLAH
 * SELURUH ALOKASI yang tidak sama dengan nilai SPK-nya. Selisih yang tak bisa dijelaskan
 * membuat orang meragukan seluruh tabel — dan tabel ini dipakai membayar kontraktor. Karena
 * itu separuh kasus di sini memeriksa totalnya, bukan barisnya.
 *
 * Jalankan: sudo docker exec ipro-fo-app php tests/Manual/uji-alokasi-spk-unit.php
 */

$lulus = 0;
$gagal = 0;

$cek = function (string $label, $dapat, $harap) use (&$lulus, &$gagal) {
    $ok = is_float($dapat) || is_int($dapat)
        ? abs((float) $dapat - (float) $harap) < 0.005
        : $dapat === $harap;
    $ok ? $lulus++ : $gagal++;
    printf("  %-5s %-46s %18s   (harap %s)\n", $ok ? 'OK' : 'GAGAL', $label,
        is_numeric($dapat) ? number_format((float) $dapat, 0, ',', '.') : var_export($dapat, true),
        is_numeric($harap) ? number_format((float) $harap, 0, ',', '.') : var_export($harap, true));
};

/**
 * Rumus yang sama dengan AlokasiSpkUnit: proporsional menurut luas, sisa pembulatan
 * ditempelkan pada unit berluas terbesar.
 *
 * @param  array<string,float>  $luas
 * @return array<string,float>
 */
$bagi = function (float $nilai, array $luas): array {
    $total = array_sum($luas);
    if ($total <= 0) {
        return [];
    }

    $hasil = [];
    foreach ($luas as $k => $l) {
        $hasil[$k] = (float) round($nilai * $l / $total);
    }

    $selisih = round($nilai - array_sum($hasil));
    if (abs($selisih) >= 0.5) {
        $kunciTerbesar = array_search(max($luas), $luas, true);
        $hasil[$kunciTerbesar] += $selisih;
    }

    return $hasil;
};

echo "\n=== Pembagian sederhana: tiga unit berluas sama ===\n";
$h = $bagi(300_000_000, ['A' => 39.0, 'B' => 39.0, 'C' => 39.0]);
$cek('unit A', $h['A'], 100_000_000);
$cek('unit B', $h['B'], 100_000_000);
$cek('TOTAL = nilai SPK', array_sum($h), 300_000_000);

echo "\n=== Luas berbeda: proporsional ===\n";
// LB 39 / 45 / 36, total 120. 240 jt dibagi menurut porsinya.
$h = $bagi(240_000_000, ['A' => 39.0, 'B' => 45.0, 'C' => 36.0]);
$cek('unit A (39/120)', $h['A'], 78_000_000);
$cek('unit B (45/120)', $h['B'], 90_000_000);
$cek('unit C (36/120)', $h['C'], 72_000_000);
$cek('TOTAL = nilai SPK', array_sum($h), 240_000_000);

echo "\n=== Pembulatan: angka yang tidak habis dibagi ===\n";
// 1.000.000.000 / 3 unit sama luas = 333.333.333,33 per unit. Tanpa penanganan sisa,
// jumlahnya 999.999.999 — kurang satu rupiah dari SPK-nya.
$h = $bagi(1_000_000_000, ['A' => 10.0, 'B' => 10.0, 'C' => 10.0]);
$cek('TOTAL tetap persis', array_sum($h), 1_000_000_000);
$cek('sisa menempel di salah satu', max($h), 333_333_334);

echo "\n=== Pembulatan pada luas ganjil ===\n";
$h = $bagi(17_756_129_619, ['A' => 87.5, 'B' => 105.0, 'C' => 60.25, 'D' => 72.75]);
$cek('TOTAL tetap persis', array_sum($h), 17_756_129_619);
$cek('sisa menempel pada luas TERBESAR (B)', $h['B'] > $h['A'] && $h['B'] > $h['D'], true);

echo "\n=== Penjaga: tanpa luas, tidak membagi apa pun ===\n";
// Membagi rata saat luas kosong akan menghasilkan angka yang tampak masuk akal untuk dasar
// yang tidak ada. Lebih baik kosong, dan layar mengatakan kenapa.
$cek('semua luas nol -> daftar kosong', $bagi(500_000_000, ['A' => 0.0, 'B' => 0.0]), []);
$cek('tanpa unit -> daftar kosong', $bagi(500_000_000, []), []);

echo "\n=== Satu unit menerima seluruhnya ===\n";
$h = $bagi(463_932_119, ['A' => 39.0]);
$cek('unit tunggal', $h['A'], 463_932_119);

printf("\n  LULUS %d · GAGAL %d\n", $lulus, $gagal);

exit($gagal > 0 ? 1 : 0);
