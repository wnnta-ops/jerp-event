<?php

declare(strict_types=1);

/**
 * Uji: kolom nilai SPK untuk Lampiran 2 (BarisSpkMuk) — dipakai bersama oleh sub modul
 * Kontrak SPK (MUK) dan layar Informasi CTC.
 *
 * Yang diuji KELAS SUNGGUHAN, bukan tiruan rumusnya: kontraknya dibuat di memori dan tidak
 * pernah disimpan, jadi uji ini tidak butuh database.
 *
 * YANG PALING MAHAL BILA SALAH adalah kolom Belum Tertagih. Ia memakai NILAI SPK dikurangi
 * yang sudah tertagih (sisa kontrak — itulah arti CTC), sedangkan layar Kontrak SPK memakai
 * OPNAME dikurangi tertagih (berapa yang layak ditagih sekarang). Keduanya benar untuk
 * pertanyaannya masing-masing dan angkanya memang berbeda; yang berbahaya adalah bila
 * suatu saat ada yang "memperbaiki" salah satunya supaya cocok.
 *
 * Jalankan: sudo docker exec ipro-fo-app php tests/Manual/uji-baris-spk-muk.php
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Services\BarisSpkMuk;

$b = new BarisSpkMuk;
$lulus = 0;
$gagal = 0;

$cek = function (string $label, $dapat, $harap) use (&$lulus, &$gagal) {
    $ok = is_float($harap) || is_int($harap)
        ? (is_numeric($dapat) && abs((float) $dapat - (float) $harap) < 0.005)
        : $dapat === $harap;
    $ok ? $lulus++ : $gagal++;
    printf("  %-5s %-46s %-22s (harap %s)\n", $ok ? 'OK' : 'GAGAL', $label,
        is_scalar($dapat) || $dapat === null ? var_export($dapat, true) : json_encode($dapat),
        is_scalar($harap) || $harap === null ? var_export($harap, true) : json_encode($harap));
};

/** Kontrak di memori — tidak pernah disimpan. */
$kontrak = fn (array $a) => new BsMukContract($a + [
    'no_spk' => 'SPK/UJI/1', 'nilai_kontrak' => 0, 'kum_diopname' => 0,
    'kum_ditagih_neto' => 0, 'kum_diterima' => 0, 'kum_retensi_ditahan' => 0,
]);

/** Baris pemetaan Setup Project (hasil petaProyek()). */
$proyek = fn (?string $cluster, ?string $tahap, ?string $code = 'C9T1') => (object) [
    'cluster' => $cluster, 'tahap' => $tahap, 'code' => $code,
];

echo "\n=== Kolom dasar ===\n";
$r = $b->kolom($kontrak(['no_spk' => 'SPK/2026/0012', 'nilai_kontrak' => 1_500_000_000]),
    $proyek('JAVA RESIDENCE KRIAN 3', '4'));
$cek('Nomor SPK', $r['no_spk'], 'SPK/2026/0012');
$cek('Lokasi dari pemetaan Setup Project', $r['lokasi'], 'JAVA RESIDENCE KRIAN 3');
$cek('Tahap dari pemetaan Setup Project', $r['tahap'], '4');
$cek('Nilai SPK (DPP) = nilai_kontrak', $r['spk_dpp'], 1_500_000_000.0);

echo "\n=== PPN & PPh belum dikirim MUK: null, BUKAN nol ===\n";
// Nol adalah angka, dan orang akan memakainya untuk membayar. null bisa dibedakan di layar
// menjadi "belum dari MUK".
$cek('Nilai SPK (PPN)', $r['spk_ppn'], null);
$cek('Nilai SPK (PPh 4 ayat 2)', $r['spk_pph42'], null);
$cek('Tagihan = DPP selama keduanya kosong', $r['spk_tagihan'], 1_500_000_000.0);

echo "\n=== Sudah & Belum Tertagih ===\n";
$r = $b->kolom($kontrak(['nilai_kontrak' => 1_000_000_000, 'kum_ditagih_neto' => 400_000_000]),
    $proyek('CLUSTER 9', '1'));
$cek('Sudah Tertagih = kum_ditagih_neto', $r['sudah_tertagih'], 400_000_000.0);
$cek('Belum Tertagih = Tagihan - Sudah', $r['belum_tertagih'], 600_000_000.0);
$cek('tidak ditandai janggal', $r['janggal_tertagih'], false);

$r = $b->kolom($kontrak(['nilai_kontrak' => 800_000_000, 'kum_ditagih_neto' => 800_000_000]), null);
$cek('tertagih penuh -> belum = 0', $r['belum_tertagih'], 0.0);
$cek('tertagih penuh bukan janggal', $r['janggal_tertagih'], false);

echo "\n=== Belum Tertagih memakai NILAI SPK, bukan opname ===\n";
// Pembeda paling penting dari layar Kontrak SPK. Opname di bawah sengaja jauh lebih kecil
// dari nilai kontraknya: kalau suatu saat rumusnya tergeser ke opname, baris ini gagal.
$r = $b->kolom($kontrak([
    'nilai_kontrak' => 1_000_000_000, 'kum_diopname' => 300_000_000, 'kum_ditagih_neto' => 250_000_000,
]), null);
$cek('belum = 1.000jt - 250jt (BUKAN 300jt - 250jt)', $r['belum_tertagih'], 750_000_000.0);

echo "\n=== Angka MUK yang melampaui nilai kontraknya sendiri ===\n";
// Terjadi sungguhan pada sebagian kontrak MUK. Sisa negatif di layar mengundang orang
// mengurangi tagihan yang sudah benar, jadi dijepit ke nol — tetapi DITANDAI, sebab nol
// yang tak bertanda terbaca seperti "sudah lunas".
$r = $b->kolom($kontrak(['nilai_kontrak' => 500_000_000, 'kum_ditagih_neto' => 560_000_000]), null);
$cek('belum dijepit ke nol (bukan negatif)', $r['belum_tertagih'], 0.0);
$cek('ditandai janggal', $r['janggal_tertagih'], true);

echo "\n=== Proyek MUK yang belum dipetakan di Setup Project ===\n";
// null, BUKAN string "belum dipilih": layar yang memutuskan kata-katanya, dan null bisa
// dibedakan dari cluster yang kebetulan bernama begitu.
$r = $b->kolom($kontrak(['nilai_kontrak' => 100_000_000]), null);
$cek('Lokasi null', $r['lokasi'], null);
$cek('Tahap null', $r['tahap'], null);
$cek('kode project null', $r['project_kode'], null);
$cek('nilai SPK tetap terbaca', $r['spk_dpp'], 100_000_000.0);

echo "\n=== Tahap kosong di pemetaan ===\n";
$r = $b->kolom($kontrak(['nilai_kontrak' => 1]), $proyek('CLUSTER 9', null));
$cek('Lokasi terisi, Tahap null', [$r['lokasi'], $r['tahap']], ['CLUSTER 9', null]);

printf("\n  LULUS %d · GAGAL %d\n", $lulus, $gagal);

exit($gagal > 0 ? 1 : 0);
