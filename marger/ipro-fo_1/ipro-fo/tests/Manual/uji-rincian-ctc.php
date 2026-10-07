<?php

declare(strict_types=1);

/**
 * Uji: rincian satu SPK di layar Informasi CTC (RincianCtc, Lampiran 1).
 *
 * Berdiri sendiri tanpa database dan tanpa MUK — kelas sungguhan yang diuji, tagihannya
 * dioper sebagai array persis seperti yang dipulangkan MukApi::tagihan().
 *
 * KASUS PERTAMA ADALAH CONTOH DARI PEMILIK PROSES, angka per angka. Kalau suatu saat
 * rumusnya digeser, baris itulah yang gagal lebih dulu.
 *
 * Jalankan: sudo docker exec ipro-fo-app php tests/Manual/uji-rincian-ctc.php
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Domain\BusinessSupport\Services\RincianCtc;

$r = new RincianCtc;
$lulus = 0;
$gagal = 0;

$cek = function (string $label, $dapat, $harap) use (&$lulus, &$gagal) {
    $ok = is_float($harap) || is_int($harap)
        ? (is_numeric($dapat) && abs((float) $dapat - (float) $harap) < 0.005)
        : $dapat === $harap;
    $ok ? $lulus++ : $gagal++;
    printf("  %-5s %-48s %-20s (harap %s)\n", $ok ? 'OK' : 'GAGAL', $label,
        is_numeric($dapat) ? number_format((float) $dapat, 0, ',', '.')
            : (is_scalar($dapat) || $dapat === null ? var_export($dapat, true) : json_encode($dapat)),
        is_numeric($harap) ? number_format((float) $harap, 0, ',', '.')
            : (is_scalar($harap) || $harap === null ? var_export($harap, true) : json_encode($harap)));
};

echo "\n=== Contoh pemilik proses: 2.000 jt, dibayar 1.260 jt, ber-COP 690 jt ===\n";
// Empat tagihan sudah LUNAS (jadi riwayat pembayaran), dua tagihan MASIH BERSISA (jadi
// Sudah Tertagih). Sisa 740 jt, ber-COP 690 jt, belum ditagihkan sama sekali 50 jt.
$tagihan = [
    ['no_tagihan' => 'COP/01', 'tanggal' => '2026-06-01', 'total' => 300_000_000, 'diterima' => 300_000_000],
    ['no_tagihan' => 'COP/02', 'tanggal' => '2026-07-01', 'total' => 310_000_000, 'diterima' => 310_000_000],
    ['no_tagihan' => 'COP/03', 'tanggal' => '2026-08-01', 'total' => 320_000_000, 'diterima' => 320_000_000],
    ['no_tagihan' => 'COP/04', 'tanggal' => '2026-09-01', 'total' => 330_000_000, 'diterima' => 330_000_000],
    ['no_tagihan' => 'XXX', 'tanggal' => '2026-09-15', 'total' => 340_000_000, 'diterima' => 0],
    ['no_tagihan' => 'YYY', 'tanggal' => '2026-09-20', 'total' => 350_000_000, 'diterima' => 0],
];
$h = $r->untuk(2_000_000_000, 1_260_000_000, $tagihan);
$cek('4 baris riwayat pembayaran', count($h['pembayaran']), 4);
$cek('total pembayaran 1.260 jt', $h['total_pembayaran'], 1_260_000_000);
$cek('SISA PEMBAYARAN 740 jt', $h['sisa_pembayaran'], 740_000_000);
$cek('2 baris sudah tertagih', count($h['tertagih']), 2);
$cek('SUDAH TERTAGIH 690 jt', $h['sudah_tertagih'], 690_000_000);
$cek('BELUM TERTAGIH 50 jt', $h['belum_tertagih'], 50_000_000);
$cek('tidak ada selisih dgn kum_diterima', $h['selisih_diterima'], null);
$cek('baris pertama 300 jt', $h['pembayaran'][0]['nominal'], 300_000_000);
$cek('baris pertama bertanggal tagihannya', $h['pembayaran'][0]['tanggal'], '2026-06-01');
$cek('tertagih pertama XXX', $h['tertagih'][0]['no_tagihan'], 'XXX');

echo "\n=== Tagihan LUNAS tidak dihitung dua kali ===\n";
// Kalau nilai PENUH seluruh tagihan dijumlahkan ke Sudah Tertagih, uang yang sama terhitung
// dua kali dan Belum Tertagih berubah negatif tanpa sebab yang bisa dijelaskan.
$h = $r->untuk(1_000_000_000, 400_000_000, [
    ['no_tagihan' => 'A', 'tanggal' => '2026-01-01', 'total' => 400_000_000, 'diterima' => 400_000_000],
]);
$cek('sudah tertagih 0 (tagihannya lunas)', $h['sudah_tertagih'], 0.0);
$cek('belum tertagih 600 jt', $h['belum_tertagih'], 600_000_000);

echo "\n=== Tagihan dibayar SEBAGIAN: sisanya saja yang tertagih ===\n";
$h = $r->untuk(1_000_000_000, 100_000_000, [
    ['no_tagihan' => 'A', 'tanggal' => '2026-01-01', 'total' => 400_000_000, 'diterima' => 100_000_000],
]);
$cek('pembayaran 100 jt', $h['total_pembayaran'], 100_000_000);
$cek('sisa pembayaran 900 jt', $h['sisa_pembayaran'], 900_000_000);
$cek('sudah tertagih 300 jt (sisa tagihan)', $h['sudah_tertagih'], 300_000_000);
$cek('belum tertagih 600 jt', $h['belum_tertagih'], 600_000_000);

echo "\n=== Belum ada tagihan sama sekali ===\n";
$h = $r->untuk(500_000_000, 0, []);
$cek('tanpa pembayaran', $h['total_pembayaran'], 0.0);
$cek('sisa = seluruh nilai SPK', $h['sisa_pembayaran'], 500_000_000);
$cek('sudah tertagih 0', $h['sudah_tertagih'], 0.0);
$cek('belum tertagih = seluruhnya', $h['belum_tertagih'], 500_000_000);

echo "\n=== Belum Tertagih negatif TIDAK dirapikan ===\n";
// COP melebihi sisa kontrak. Keadaan yang harus TERLIHAT dan ditanyakan ke MUK; dijepit ke
// nol ia terbaca "sudah beres".
$h = $r->untuk(500_000_000, 0, [
    ['no_tagihan' => 'A', 'tanggal' => '2026-01-01', 'total' => 600_000_000, 'diterima' => 0],
]);
$cek('belum tertagih -100 jt (dibiarkan)', $h['belum_tertagih'], -100_000_000);

echo "\n=== Angka MUK tidak konsisten: selisih DIKATAKAN ===\n";
// Jumlah 'diterima' per tagihan tidak sama dengan 'kum_diterima' di kepala kontrak.
$h = $r->untuk(1_000_000_000, 500_000_000, [
    ['no_tagihan' => 'A', 'tanggal' => '2026-01-01', 'total' => 400_000_000, 'diterima' => 400_000_000],
]);
$cek('selisih 100 jt dipulangkan', $h['selisih_diterima'], 100_000_000);

echo "\n=== Pembayaran dari BO ikut bila kelak tertaut ===\n";
// Seam untuk jalur posting AP dari kontrak MUK, yang belum ada. Diuji supaya ketika jalur
// itu dibuat, aritmetikanya sudah terbukti menerimanya.
$h = $r->untuk(1_000_000_000, 0, [
    ['no_tagihan' => 'A', 'tanggal' => '2026-03-01', 'total' => 200_000_000, 'diterima' => 0],
], [
    ['tanggal' => '2026-02-01', 'nominal' => 150_000_000, 'sumber' => 'bo', 'keterangan' => 'Kuitansi AP/1'],
]);
$cek('1 baris pembayaran dari BO', count($h['pembayaran']), 1);
$cek('sisa 850 jt', $h['sisa_pembayaran'], 850_000_000);
$cek('sudah tertagih 200 jt', $h['sudah_tertagih'], 200_000_000);
$cek('belum tertagih 650 jt', $h['belum_tertagih'], 650_000_000);

echo "\n=== Tagihan yang dibayar lewat BO: 'diterima' MUK DILEWATI ===\n";
// Begitu MUK ikut mencatat pembayaran yang kita kirim, kedua sisi menggambarkan uang yang
// SAMA. Menjumlahkannya membuat Sisa Pembayaran menyusut dua kali lipat tanpa satu pun galat
// — dan itu angka yang dipakai memutuskan pembayaran berikutnya.
$h = $r->untuk(1_000_000_000, 0, [
    ['no_tagihan' => 'A', 'tanggal' => '2026-03-01', 'total' => 200_000_000, 'diterima' => 200_000_000],
], [
    ['tanggal' => '2026-03-05', 'nominal' => 200_000_000, 'sumber' => 'bo',
        'keterangan' => 'Kuitansi AP/9', 'no_tagihan' => 'A'],
]);
$cek('hanya SATU baris pembayaran', count($h['pembayaran']), 1);
$cek('barisnya yang dari BO', $h['pembayaran'][0]['sumber'], 'bo');
$cek('total pembayaran 200 jt, bukan 400 jt', $h['total_pembayaran'], 200_000_000);
$cek('sisa 800 jt, bukan 600 jt', $h['sisa_pembayaran'], 800_000_000);

echo "\n=== Tagihan LAIN yang belum dibayar BO tetap dari MUK ===\n";
$h = $r->untuk(1_000_000_000, 0, [
    ['no_tagihan' => 'A', 'tanggal' => '2026-03-01', 'total' => 200_000_000, 'diterima' => 200_000_000],
    ['no_tagihan' => 'B', 'tanggal' => '2026-04-01', 'total' => 100_000_000, 'diterima' => 100_000_000],
], [
    ['tanggal' => '2026-03-05', 'nominal' => 200_000_000, 'sumber' => 'bo',
        'keterangan' => 'Kuitansi AP/9', 'no_tagihan' => 'A'],
]);
$cek('dua baris: BO utk A, MUK utk B', count($h['pembayaran']), 2);
$cek('total 300 jt', $h['total_pembayaran'], 300_000_000);

echo "\n=== Urutan baris menurut tanggal, bukan urutan sumbernya ===\n";
$h = $r->untuk(1_000_000_000, 0, [
    ['no_tagihan' => 'A', 'tanggal' => '2026-05-01', 'total' => 100_000_000, 'diterima' => 100_000_000],
], [
    ['tanggal' => '2026-01-01', 'nominal' => 50_000_000, 'sumber' => 'bo', 'keterangan' => 'lebih dulu'],
]);
$cek('yang Januari di baris pertama', $h['pembayaran'][0]['keterangan'], 'lebih dulu');
$cek('yang Mei di baris kedua', $h['pembayaran'][1]['sumber'], 'muk');

printf("\n  LULUS %d · GAGAL %d\n", $lulus, $gagal);

exit($gagal > 0 ? 1 : 0);
