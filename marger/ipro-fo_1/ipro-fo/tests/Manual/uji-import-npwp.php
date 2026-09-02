<?php

declare(strict_types=1);

/*
 * Uji pembacaan NPWP pada import massal supplier.
 *
 * Aturan lama menuntut TEPAT 16 angka. Pada daftar supplier sungguhan milik MUP, aturan itu
 * menolak 427 dari 779 baris — bukan karena datanya salah, melainkan karena:
 *
 *   · NPWP LAMA 15 ANGKA masih sah. Format 00.000.000.0-000.000 dipakai sampai Coretax 2024;
 *     16 angka (mengikuti NIK) baru berlaku untuk orang pribadi sesudahnya. Keduanya beredar
 *     berdampingan — 364 baris berformat lama, 40 baris berformat baru.
 *
 *   · EXCEL MEMAKAN NOL DEPAN. Bila kolomnya bertipe angka, "054963137617000" tersimpan
 *     sebagai 54963137617000. Kekurangannya hanya bisa di depan dan jumlahnya pasti, jadi
 *     nilainya disusun kembali persis — bukan ditebak (59 baris).
 *
 *   · HURUF O DITULIS SEBAGAI NOL. "055640221652OOO" — di kolom yang isinya angka semata,
 *     huruf O tak punya arti lain.
 *
 * Yang TIDAK ditambal: nilai di bawah 13 angka. Itu bukan nol depan yang hilang melainkan
 * ada bagian yang memang tak tertulis, dan kelompok terakhir NPWP (kode cabang) tidak bisa
 * ditebak — 000 pada kantor pusat, 001/002/… pada cabang. Menambalnya berarti mengarang
 * nomor pajak.
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-import-npwp.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Import\TabelImpor;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal) {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};

/** [masukan, npwp yang diharapkan (null = tanpa NPWP), dipulihkan?, ditolak?] */
$kasus = [
    // ── Diterima apa adanya ───────────────────────────────────────────────────────────────
    ['962016028654000',      '962016028654000',  false, false],  // 15 angka, format lama
    ['1234567890123456',     '1234567890123456', false, false],  // 16 angka, format Coretax
    ['96.201.602.8-654.000', '962016028654000',  false, false],  // bertitik & bertanda hubung
    ['  962016028654000  ',  '962016028654000',  false, false],  // berspasi

    // ── Tidak ber-NPWP (bukan galat) ──────────────────────────────────────────────────────
    ['',    null, false, false],
    ['0',   null, false, false],   // daftar lama menulis "tidak ada" sebagai angka nol
    ['000', null, false, false],
    ['-',   null, false, false],

    // ── Dipulihkan ────────────────────────────────────────────────────────────────────────
    ['54963137617000',  '054963137617000', true, false],   // 14 angka: 1 nol depan hilang
    ['5496313761700',   '005496313761700', true, false],   // 13 angka: 2 nol depan hilang
    ['055640221652OOO', '055640221652000', true, false],   // huruf O sebagai nol
    ['055640221652ooo', '055640221652000', true, false],   // huruf o kecil

    // ── Ditolak ───────────────────────────────────────────────────────────────────────────
    ['016542300029',     null, false, true],   // 12 angka: ada bagian yang tak tertulis
    ['01.654.230.0-029', null, false, true],   // sama, dalam bentuk bertitik
    ['12345678901234567', null, false, true],  // 17 angka: kepanjangan
];

echo "\n=== 1. Tiap bentuk NPWP dibaca sebagaimana mestinya ===\n";

foreach ($kasus as [$masukan, $mau, $mauPulih, $mauTolak]) {
    [$npwp, $pulih, $galat] = TabelImpor::npwp($masukan);
    $label = "'".($masukan === '' ? '(kosong)' : $masukan)."'";

    $ok = $npwp === $mau && $pulih === $mauPulih && ($galat !== null) === $mauTolak;
    $cek($label.' → '.($mauTolak ? 'ditolak' : ($mau ?? 'tanpa NPWP')).($mauPulih ? ' (dipulihkan)' : ''),
        $ok, 'dapat npwp='.var_export($npwp, true).' pulih='.var_export($pulih, true)
            .' galat='.($galat === null ? 'tidak ada' : $galat));
}

echo "\n=== 2. Penambalan tidak boleh mengubah panjang yang sudah sah ===\n";

// Kalau batas penambalan meleset satu angka saja, NPWP 15 angka ikut ditambal jadi 16 dan
// seluruh data lama tersimpan salah — diam-diam, karena panjangnya tetap "sah".
foreach (['962016028654000' => 15, '1234567890123456' => 16] as $masukan => $panjang) {
    [$npwp, $pulih] = TabelImpor::npwp((string) $masukan);
    $cek("NPWP {$panjang} angka tidak ikut ditambal",
        $npwp === (string) $masukan && $pulih === false, 'dapat '.var_export($npwp, true));
}

echo "\n=== 3. Yang ditolak diberi tahu apa masalahnya ===\n";

// Pesan "bukan 16 angka" pada NPWP 15 angka yang sah adalah alasan orang menyerah memakai
// fitur ini. Pesannya harus menyebut angka yang terbaca dan yang diharapkan.
[, , $g12] = TabelImpor::npwp('016542300029');
$cek('pesannya menyebut jumlah angka yang terbaca', str_contains((string) $g12, '12 angka'), (string) $g12);
$cek('pesannya menyebut panjang yang benar', str_contains((string) $g12, '15 atau 16'), (string) $g12);
$cek('pesannya menjelaskan kemungkinan penyebabnya',
    str_contains((string) $g12, '00.000.000.0-000.000'), (string) $g12);

echo "\n=== 4. Nol depan yang dipulihkan tetap dilaporkan ===\n";

// Yang tersimpan berbeda dari yang tertulis di berkasnya. Operator berhak tahu itu terjadi,
// dan tahu cara mencegahnya terulang.
[, $pulih14] = TabelImpor::npwp('54963137617000');
$cek('penambalan ditandai supaya bisa dilaporkan', $pulih14 === true);
[, $pulih15] = TabelImpor::npwp('962016028654000');
$cek('yang tidak ditambal tidak ikut dilaporkan', $pulih15 === false);

echo "\n".str_repeat('─', 78)."\n";
echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";

exit($gagal === 0 ? 0 : 1);
