<?php
// Uji hasil pemindahan Data Supplier software lama ke Supplier Center.
//
// Membandingkan isi database dengan berkas sumbernya, memastikan koreksi pergeseran kolom
// benar-benar terjadi, dan memastikan kode supplier berikutnya MENERUSKAN nomor SP
// tertinggi — bukan memulai deret baru.
//
// Bagian yang menulis dibungkus transaksi lalu di-rollback; bagian yang membaca memakai
// data hasil impor apa adanya.

use Illuminate\Support\Facades\DB;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Http\Controllers\Web\BusinessSupport\Setup\SupplierController;

$lolos = 0;
$gagal = 0;
$ok = function (bool $c, string $n) use (&$lolos, &$gagal) {
    $c ? $lolos++ : $gagal++;
    echo ($c ? '  OK    ' : '  GAGAL ').$n."\n";
};

try {
    $sumber = json_decode((string) file_get_contents(database_path('data/suppliers-legacy.json')), true);
    $ok(is_array($sumber) && count($sumber) === 757, 'berkas sumber berisi 757 supplier: '.count($sumber));

    echo "\n══ Seluruh baris masuk ══\n";
    $adaSp = BsSupplier::where('code', 'like', 'SP.%')->count();
    $ok($adaSp === 757, "757 supplier berkode SP. ada di Supplier Center: {$adaSp}");
    $kodeSumber = collect($sumber)->pluck('code')->sort()->values();
    $kodeDb = BsSupplier::where('code', 'like', 'SP.%')->orderBy('code')->pluck('code')->values();
    $ok($kodeSumber->all() === $kodeDb->all(), '  daftar kodenya sama persis dengan berkas sumber');

    echo "\n══ Isi kolom cocok baris per baris ══\n";
    $kolom = ['name', 'business_name', 'npwp', 'phone', 'phone2', 'fax', 'email',
        'address', 'address_billing', 'address_mail', 'address_identity'];
    $db = BsSupplier::where('code', 'like', 'SP.%')->get(array_merge(['code'], $kolom))->keyBy('code');
    $beda = [];
    foreach ($sumber as $r) {
        $m = $db->get($r['code']);
        if (! $m) { $beda[] = $r['code'].' (tidak ada)'; continue; }
        foreach ($kolom as $k) {
            if ((string) ($m->{$k} ?? '') !== (string) ($r[$k] ?? '')) {
                $beda[] = $r['code'].'.'.$k.': db="'.($m->{$k} ?? '').'" vs sumber="'.($r[$k] ?? '').'"';
            }
        }
    }
    $ok($beda === [], 'ke-757 baris cocok di seluruh 11 kolom'.($beda ? ' — beda: '.implode(' | ', array_slice($beda, 0, 5)) : ''));

    echo "\n══ Koreksi pergeseran kolom benar-benar terjadi ══\n";
    // Baris yang di Excel-nya menaruh NPWP di kolom Nama Bisnis.
    $contoh = ['SP.00410' => '962016028654000', 'SP.00197' => '643160773617000', 'SP.00282' => '620448209603000'];
    foreach ($contoh as $kode => $npwp) {
        $m = $db->get($kode);
        $ok($m && $m->npwp === $npwp && ($m->business_name ?? null) === null,
            "{$kode}: NPWP {$npwp} masuk kolom npwp, nama bisnis dibiarkan kosong");
    }
    $angkaSbgNama = BsSupplier::where('code', 'like', 'SP.%')
        ->whereRaw("business_name REGEXP '^[0-9]{12,16}$'")->count();
    $ok($angkaSbgNama === 0, '  tidak ada supplier yang nama bisnisnya berupa deretan angka NPWP: '.$angkaSbgNama);
    $nolSbgNama = BsSupplier::where('code', 'like', 'SP.%')->where('business_name', '0')->count();
    $ok($nolSbgNama === 0, '  tidak ada supplier yang nama bisnisnya "0": '.$nolSbgNama);
    $punyaNpwp = BsSupplier::where('code', 'like', 'SP.%')->whereNotNull('npwp')->count();
    $ok($punyaNpwp === 454, '  454 supplier ber-NPWP (304 dari kolomnya + 150 hasil koreksi): '.$punyaNpwp);

    echo "\n══ Kode berikutnya MENERUSKAN nomor SP tertinggi ══\n";
    $tertinggi = BsSupplier::withTrashed()->where('code', 'like', 'SP.%')
        ->selectRaw('MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) AS n')->value('n');
    $ok((int) $tertinggi === 764, 'nomor SP tertinggi = 764 (SP.00764): '.$tertinggi);

    $ref = new ReflectionMethod(SupplierController::class, 'nextCode');
    $ref->setAccessible(true);
    $berikut = $ref->invoke(app(SupplierController::class));
    $ok($berikut === 'SP.00765', 'kode berikutnya SP.00765 — melanjutkan, bukan mulai dari awal: '.$berikut);

    DB::beginTransaction();
    try {
        BsSupplier::create(['code' => $berikut, 'name' => 'UJI SUPPLIER BARU', 'is_active' => true]);
        $lagi = $ref->invoke(app(SupplierController::class));
        $ok($lagi === 'SP.00766', '  sesudah SP.00765 dipakai, berikutnya SP.00766: '.$lagi);
    } finally {
        DB::rollBack();
    }

    // Perbandingan angka, bukan teks: kalau diurutkan sebagai teks, SP.0099 akan mengalahkan
    // SP.00764 dan kode berikutnya menabrak yang sudah terpakai.
    DB::beginTransaction();
    try {
        // SP.00002 dipilih karena berada DI BAWAH nomor terkecil yang diimpor (SP.00006),
        // jadi dipastikan belum terpakai — kode di tengah rentang 6..764 hampir semuanya ada.
        BsSupplier::create(['code' => 'SP.00002', 'name' => 'UJI KODE PENDEK', 'is_active' => true]);
        $lagi = $ref->invoke(app(SupplierController::class));
        $ok($lagi === 'SP.00765', '  kode berangka kecil tidak menggeser deret: tetap '.$lagi);
    } finally {
        DB::rollBack();
    }

    echo "\n══ Kode gaya lama tidak ikut menyeret deret ══\n";
    $lama = BsSupplier::where('code', 'like', 'SUP-%')->count();
    $ok($lama >= 0, "supplier contoh bergaya SUP- masih ada {$lama} baris, dan dibiarkan apa adanya");
    DB::beginTransaction();
    try {
        BsSupplier::create(['code' => 'SUP-9999', 'name' => 'UJI SUP LAMA', 'is_active' => true]);
        $lagi = $ref->invoke(app(SupplierController::class));
        $ok($lagi === 'SP.00765', '  SUP-9999 tidak menyeret deret SP: tetap '.$lagi);
    } finally {
        DB::rollBack();
    }

    echo "\n{$lolos} lolos, {$gagal} gagal\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
}
exit($gagal ? 1 : 0);
