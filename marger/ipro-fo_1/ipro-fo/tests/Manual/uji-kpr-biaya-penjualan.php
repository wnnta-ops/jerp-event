<?php

/**
 * Master Data KPR — "Rincian Biaya Gimmick" (nama baru) + kelompok baru "Rincian Biaya
 * Penjualan": BLT SALES, BLT KOORDINATOR, BLT KANTOR, KOMISI.
 *
 * YANG MEMBEDAKAN KEDUA KELOMPOK BUKAN PENAMAANNYA. Biaya gimmick dipetakan operator lewat
 * Setup Kategori Gimmick (satu kategori → satu kolom, dengan sakelar sinkron). Keempat biaya
 * penjualan TIDAK punya pemetaan itu sama sekali: jenjang BLT sudah ditentukan dokumen BLT-nya
 * sendiri, dan komisi hanya punya satu kolom. Menyediakan pemetaan kategori untuknya berarti
 * membuka tempat kedua yang bisa berbeda dari jenjang aslinya — dua sumber kebenaran untuk satu
 * hal yang sama.
 *
 * Karena itu berkas ini tidak cukup memeriksa "kolomnya muncul": ia mengunci bahwa keempat
 * kunci itu TIDAK ditawarkan di Setup Kategori Gimmick, dan bahwa jalur pencatatannya
 * benar-benar melewati Kategori Gimmick.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-kpr-biaya-penjualan.php';"
 */

use App\Domain\BusinessSupport\Models\BsGimmickCategory;
use App\Domain\BusinessSupport\Models\BsKprBiayaEntry;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

echo "\n=== 1. EMPAT KOLOM PENJUALAN ADA, DENGAN LABEL YANG DIMINTA ===\n";

$penjualan = BsKprBiayaEntry::KOLOM_PENJUALAN;
$ok('ada tepat 4 kolom penjualan', count($penjualan) === 4, (string) count($penjualan));
foreach (['blt_sales' => 'BLT SALES', 'blt_koord' => 'BLT KOORDINATOR',
    'blt_kantor' => 'BLT KANTOR', 'komisi' => 'KOMISI'] as $k => $label) {
    $ok("kolom {$label} ada", ($penjualan[$k] ?? null) === $label, (string) ($penjualan[$k] ?? 'tidak ada'));
}
// biaya_key pada bs_kpr_biaya_entries varchar(16) — kunci yang lebih panjang akan terpotong
// diam-diam oleh MySQL dan tidak akan pernah cocok saat dibaca kembali.
$ok('semua kunci muat di kolom biaya_key (<= 16 karakter)',
    ! array_filter(array_keys($penjualan), fn ($k) => strlen($k) > 16),
    json_encode(array_map('strlen', array_flip(array_keys($penjualan)))));

echo "\n=== 2. TIDAK ADA SETUP KATEGORI GIMMICK UNTUK KEEMPATNYA ===\n";

// Inilah inti permintaannya. KPR_BIAYA_COLUMNS-lah yang mengisi dropdown Setup Kategori
// Gimmick, jadi keempat kunci ini tidak boleh ada di sana.
$gimmick = BsGimmickCategory::KPR_BIAYA_COLUMNS;
foreach (array_keys($penjualan) as $k) {
    $ok("kunci '{$k}' TIDAK ditawarkan di Kategori Gimmick", ! array_key_exists($k, $gimmick));
}
$ok('kolom gimmick tetap 6 seperti semula', count($gimmick) === 6, (string) count($gimmick));
$ok('kedua kelompok tidak beririsan',
    array_intersect(array_keys($gimmick), array_keys($penjualan)) === []);
$ok('gabungannya 10 kolom', count(BsKprBiayaEntry::kolomSah()) === 10,
    (string) count(BsKprBiayaEntry::kolomSah()));

echo "\n=== 3. KUNCI DITENTUKAN LANGSUNG DARI REALISASINYA ===\n";

// Stempel dari FO dipakai apa adanya bila ada.
$ok('stempel blt_koord dipakai', BsKprBiayaEntry::kunciPenjualan('blt', 'blt_koord', 'apa saja') === 'blt_koord');
$ok('stempel komisi dipakai', BsKprBiayaEntry::kunciPenjualan('komisi', 'komisi', null) === 'komisi');

/* Cadangan tanpa stempel — baris yang telanjur mengantre sebelum FO diperbarui. Keterangannya
   memang dibentuk IproApPoster sebagai "BLT Sales …" / "BLT Koordinator …" / "BLT Kantor …". */
$ok('tanpa stempel: BLT Sales terbaca',
    BsKprBiayaEntry::kunciPenjualan('blt', null, 'BLT Sales BLTPENG-202609-00003 BUDI, JRK3-004') === 'blt_sales');
$ok('tanpa stempel: BLT Koordinator terbaca',
    BsKprBiayaEntry::kunciPenjualan('blt', null, 'BLT Koordinator BLTPENG-202609-00003 BUDI') === 'blt_koord');
$ok('tanpa stempel: BLT Kantor terbaca',
    BsKprBiayaEntry::kunciPenjualan('blt', null, 'BLT Kantor BLTPENG-202609-00003 BUDI') === 'blt_kantor');
$ok('tanpa stempel: komisi selalu satu kolom',
    BsKprBiayaEntry::kunciPenjualan('komisi', null, 'Komisi KOMPENG-202609-00001 BUDI') === 'komisi');

// Koordinator harus diperiksa SEBELUM Sales: "BLT Koordinator" tidak boleh jatuh ke Sales.
$ok('"BLT Koordinator" tidak tertukar dengan Sales',
    BsKprBiayaEntry::kunciPenjualan('blt', null, 'BLT KOORDINATOR X') !== 'blt_sales');

// Yang bukan insentif tidak boleh dapat kolom penjualan sama sekali.
$ok('kind gmc tidak dapat kolom penjualan', BsKprBiayaEntry::kunciPenjualan('gmc', null, 'Gimmick AJB') === null);
$ok('kind refund tidak dapat kolom penjualan', BsKprBiayaEntry::kunciPenjualan('refund', null, 'Refund SPR') === null);
$ok('stempel gimmick TIDAK dipakai sebagai kolom penjualan',
    BsKprBiayaEntry::kunciPenjualan('blt', 'ajb', 'BLT Sales X') === 'blt_sales',
    'stempel milik kelompok lain seharusnya diabaikan, lalu jatuh ke keterangan');

echo "\n=== 4. MASTER DATA KPR MENGIRIM KESEPULUH SEL ===\n";

$ref = new ReflectionMethod(\App\Http\Controllers\Web\BankLoan\MasterDataKprController::class, 'biayaCells');
$ref->setAccessible(true);
$ctl = app(\App\Http\Controllers\Web\BankLoan\MasterDataKprController::class);

$entri = [
    'ajb'        => ['t' => '2026-09-07', 'n' => 6_800_000.0],
    'blt_sales'  => ['t' => '2026-09-01', 'n' => 2_500_000.0],
    'komisi'     => ['t' => '2026-09-02', 'n' => 9_346_667.0],
];
$sel = $ref->invoke($ctl, $entri, null);

$ok('kesepuluh kunci ada di hasilnya', count($sel) === 10, (string) count($sel));
$ok('sel gimmick terisi apa adanya', ($sel['ajb']['n'] ?? null) === 6_800_000.0);
$ok('sel BLT SALES terisi', ($sel['blt_sales']['n'] ?? null) === 2_500_000.0);
$ok('sel KOMISI terisi', ($sel['komisi']['n'] ?? null) === 9_346_667.0);
$ok('kolom penjualan yang tak ada entrinya bernilai null',
    $sel['blt_koord'] === null && $sel['blt_kantor'] === null);

echo "\n=== 5. JALUR PENCATATAN MELEWATI KATEGORI GIMMICK ===\n";

$srcPost = file_get_contents(app_path('Http/Controllers/Web/BusinessSupport/Payable/PostingInvoiceController.php')) ?: '';
$ok('blt & komisi memakai kunciPenjualan(), bukan kprBiayaKeyAktif()',
    (bool) preg_match("/in_array\(\\\$kind, \['blt', 'komisi'\], true\)\s*\n?\s*\?\s*BsKprBiayaEntry::kunciPenjualan/", $srcPost));
$ok('kunci yang sah dibaca dari gabungan kedua kelompok',
    str_contains($srcPost, 'BsKprBiayaEntry::kolomSah()'));
$ok('sumber selain blt/komisi tetap lewat kategori gimmick',
    str_contains($srcPost, ': $this->kprBiayaKeyAktif($row)'));

echo "\n=== 6. LAYAR ===\n";

$vue = file_get_contents(resource_path('js/Pages/BankLoan/MasterData/Index.vue')) ?: '';
$ok('kelompoknya bernama "Rincian Biaya Gimmick"', str_contains($vue, "label: 'Rincian Biaya Gimmick'"));
$ok('tidak ada lagi kelompok bernama "Rincian Biaya" polos',
    ! (bool) preg_match("/label: 'Rincian Biaya',/", $vue));
$ok('kelompok "Rincian Biaya Penjualan" ada', str_contains($vue, "label: 'Rincian Biaya Penjualan'"));
foreach (['blt_sales' => 'BLT SALES', 'blt_koord' => 'BLT KOORDINATOR',
    'blt_kantor' => 'BLT KANTOR', 'komisi' => 'KOMISI'] as $k => $label) {
    $ok("layar memetakan {$k} → {$label}",
        (bool) preg_match("/\{ key: '".preg_quote($k, '/')."', label: '".preg_quote($label, '/')."' \}/", $vue));
}
// Kunci di layar harus SAMA PERSIS dengan di server; kalau berbeda, kolomnya tampil tetapi
// selalu kosong — tanpa galat apa pun.
preg_match('/const biayaPenjualanKeys = \[[\s\S]*?\];/', $vue, $m);
foreach (array_keys($penjualan) as $k) {
    $ok("kunci '{$k}' di layar cocok dengan server", str_contains($m[0] ?? '', "'{$k}'"));
}
$ok('warna kelompoknya berbeda dari Rincian Biaya Gimmick',
    str_contains($vue, 'bg-violet-50') && str_contains($vue, 'bg-teal-50'));
$ok('keterangan halaman menyebut kedua kelompok',
    str_contains($vue, 'Rincian Biaya Gimmick, dan Rincian Biaya Penjualan'));

echo "\n=== 7. FO MENSTEMPEL SPR & KUNCI KOLOMNYA ===\n";

// Berkas FO ada di ROOT repo (aplikasi terpisah, image dipanggang).
$poster = @file_get_contents(base_path('../../../app/Modules/BO/IproBridge/Services/IproApPoster.php'));
if ($poster === false) {
    echo "  (dilewati — berkas FO tidak terjangkau dari kontainer ini)\n";
} else {
    $ok('queueInsentifRow menerima no kontrak & kunci biaya',
        str_contains($poster, '?string $noKontrak = null,') && str_contains($poster, '?string $biayaKey = null,'));
    $ok('booking_id ikut diantrekan', str_contains($poster, "'booking_id'         => \$noKontrak ? \$this->iproBookingIdByNoKontrak(\$noKontrak) : null,"));
    $ok('kpr_biaya_key ikut diantrekan', str_contains($poster, "'kpr_biaya_key'      => \$biayaKey,"));
    $ok('jenjang BLT dipetakan ke kolomnya',
        str_contains($poster, "'KOORDINATOR' => 'blt_koord'")
        && str_contains($poster, "'KANTOR'      => 'blt_kantor'")
        && str_contains($poster, "default       => 'blt_sales'"));
    $ok('komisi distempel kolom komisi', str_contains($poster, "\$tgl, \$noKontrak, 'komisi',"));
}

echo "\n=== HASIL ===\n";
echo "  lulus: $lulus · gagal: $gagal\n";
echo $gagal === 0 ? "  SEMUA LULUS\n" : "  ADA YANG GAGAL\n";
