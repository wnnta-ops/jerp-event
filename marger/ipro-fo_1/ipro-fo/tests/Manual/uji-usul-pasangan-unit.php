<?php

declare(strict_types=1);

/**
 * Uji: usulan pasangan lokasi MUK ↔ unit kita (UsulPasanganUnit).
 *
 * Berdiri sendiri tanpa database — yang diuji aturan pencocokannya, dan itu murni teks.
 * Nama lokasi di bawah DISALIN dari data MUK sungguhan (tarikan 1 Okt 2026), kode unit
 * mengikuti bentuk di cermin unit BO.
 *
 * YANG PALING MAHAL BILA SALAH bukan usulan yang terlewat — itu hanya pekerjaan tangan —
 * melainkan usulan yang SALAH TAPI MEYAKINKAN. Operator menerima satu halaman sekaligus;
 * pasangan yang keliru di situ memindahkan biaya SPK ke rumah lain tanpa galat. Karena itu
 * separuh kasus di sini memastikan yang MENDUA tidak diusulkan sama sekali.
 *
 * Jalankan: sudo docker exec ipro-fo-app php tests/Manual/uji-usul-pasangan-unit.php
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Domain\BusinessSupport\Services\UsulPasanganUnit;

$u = new UsulPasanganUnit;
$lulus = 0;
$gagal = 0;

$cek = function (string $label, $dapat, $harap) use (&$lulus, &$gagal) {
    $ok = $dapat === $harap;
    $ok ? $lulus++ : $gagal++;
    printf("  %-5s %-52s %-22s (harap %s)\n", $ok ? 'OK' : 'GAGAL', $label,
        is_scalar($dapat) || $dapat === null ? var_export($dapat, true) : json_encode($dapat),
        is_scalar($harap) || $harap === null ? var_export($harap, true) : json_encode($harap));
};

echo "\n=== Kunci dari kode unit ===\n";
$cek('JRK3-004-XIX-17 (dgn tahap)', $u->kunciUnit('JRK3-004-XIX-17'), 'XIX|17');
$cek('C-A-01 (tanpa tahap)', $u->kunciUnit('C-A-01'), 'A|1');
$cek('nol di depan dihapus', $u->kunciUnit('JRK3-004-VI-05'), 'VI|5');
$cek('akhiran huruf dipertahankan', $u->kunciUnit('JRK3-004-VI-15A'), 'VI|15A');
$cek('satu potongan -> null', $u->kunciUnit('A01'), null);
$cek('kosong -> null', $u->kunciUnit(''), null);
$cek('null -> null', $u->kunciUnit(null), null);

echo "\n=== Kunci dari blok & nomor lokasi MUK ===\n";
$cek('VI + 15', $u->kunciLokasi('VI', '15'), 'VI|15');
$cek('huruf dibesarkan', $u->kunciLokasi('vi', '15'), 'VI|15');
$cek('pemisah dibuang', $u->kunciLokasi('VI-', ' 15 '), 'VI|15');
$cek('blok bukan Romawi tetap dipakai', $u->kunciLokasi('RAYA', '22'), 'RAYA|22');
$cek('blok kosong -> null', $u->kunciLokasi('', '15'), null);
$cek('nomor kosong -> null', $u->kunciLokasi('VI', ''), null);

echo "\n=== Pencocokan: kasus lurus ===\n";
// "05" di satu sisi vs "5" di sisi lain — ini yang membuat separuh rumah tidak pernah
// terusulkan bila nol di depan tidak dinormalkan.
$lok = [
    ['lokasi_kode' => '006005', 'blok' => 'II', 'no_rumah' => '5'],
    ['lokasi_kode' => '006012', 'blok' => 'VI', 'no_rumah' => '15'],
];
$unit = [
    ['id' => 101, 'unit_code' => 'JRK3-004-II-05'],
    ['id' => 102, 'unit_code' => 'JRK3-004-VI-15'],
    ['id' => 103, 'unit_code' => 'JRK3-004-VI-16'],
];
$cek('dua terpasang, yang tak disebut dilewati', $u->pasangkan($lok, $unit), ['006005' => 101, '006012' => 102]);

echo "\n=== Yang MENDUA tidak diusulkan sama sekali ===\n";
// Dua unit berkunci sama (dua tahap tercakup satu penyaring project). Memilih salah satunya
// benar separuh waktu; separuh lainnya memindahkan biaya ke rumah yang salah.
$cek('dua unit satu kunci -> tak ada usulan', $u->pasangkan(
    [['lokasi_kode' => 'A', 'blok' => 'VI', 'no_rumah' => '15']],
    [['id' => 1, 'unit_code' => 'JRK3-004-VI-15'], ['id' => 2, 'unit_code' => 'JRK3-005-VI-15']],
), []);

// Dua lokasi memperebutkan satu unit. Urutan baris bukan bukti mana yang benar, dan
// menerima keduanya akan menghitung biaya rumah itu dua kali.
$cek('dua lokasi satu unit -> keduanya dilepas', $u->pasangkan(
    [['lokasi_kode' => 'A', 'blok' => 'VI', 'no_rumah' => '15'],
        ['lokasi_kode' => 'B', 'blok' => 'VI', 'no_rumah' => '015']],
    [['id' => 1, 'unit_code' => 'JRK3-004-VI-15']],
), []);

$cek('yang tak mendua tetap terusulkan', $u->pasangkan(
    [['lokasi_kode' => 'A', 'blok' => 'VI', 'no_rumah' => '15'],
        ['lokasi_kode' => 'B', 'blok' => 'VI', 'no_rumah' => '015'],
        ['lokasi_kode' => 'C', 'blok' => 'II', 'no_rumah' => '7']],
    [['id' => 1, 'unit_code' => 'JRK3-004-VI-15'], ['id' => 9, 'unit_code' => 'JRK3-004-II-07']],
), ['C' => 9]);

echo "\n=== Yang tidak bisa diurai dilewati, tidak ditebak ===\n";
$cek('lokasi tanpa blok -> tak diusulkan', $u->pasangkan(
    [['lokasi_kode' => 'A', 'blok' => null, 'no_rumah' => null]],
    [['id' => 1, 'unit_code' => 'JRK3-004-VI-15']],
), []);
$cek('unit tanpa kunci -> tak diusulkan', $u->pasangkan(
    [['lokasi_kode' => 'A', 'blok' => 'VI', 'no_rumah' => '15']],
    [['id' => 1, 'unit_code' => 'TANPAPEMISAH']],
), []);
$cek('tanpa unit -> daftar kosong', $u->pasangkan(
    [['lokasi_kode' => 'A', 'blok' => 'VI', 'no_rumah' => '15']], []
), []);
$cek('tanpa lokasi -> daftar kosong', $u->pasangkan(
    [], [['id' => 1, 'unit_code' => 'JRK3-004-VI-15']]
), []);

echo "\n=== Nama MUK sungguhan, lewat tebakBlokNomor lalu dicocokkan ===\n";
// Penguraian namanya diuji di uji-tebak-blok-nomor.php; di sini hanya hasilnya yang dipakai,
// untuk membuktikan dua penulisan yang berbeda bertemu di kunci yang sama.
$cek('"CENDANA JRKC2 VI No. 15" <-> JRKC2-002-VI-15',
    $u->kunciLokasi('VI', '15') === $u->kunciUnit('JRKC2-002-VI-15'), true);
$cek('"AKASIA JRK RAYA No. 22" <-> JRK-001-RAYA-22',
    $u->kunciLokasi('RAYA', '22') === $u->kunciUnit('JRK-001-RAYA-22'), true);
$cek('blok beda -> BUKAN pasangan',
    $u->kunciLokasi('VI', '15') === $u->kunciUnit('JRKC2-002-II-15'), false);

printf("\n  LULUS %d · GAGAL %d\n", $lulus, $gagal);

exit($gagal > 0 ? 1 : 0);
