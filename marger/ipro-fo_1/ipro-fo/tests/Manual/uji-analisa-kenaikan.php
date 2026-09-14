<?php

/**
 * ANALISA KENAIKAN di bawah Neraca, Laba Rugi, dan Laba Rugi vs Budget.
 *
 * APA YANG DIJAGA DAN KENAPA.
 *   1. DIURUT MENURUT RUPIAH, BUKAN PERSENTASE. Akun kecil yang naik 200% harus kalah dari
 *      akun besar yang naik 15%. Mengurut menurut persentase membuat analisanya menunjuk pos
 *      remah sebagai "penggerak utama" — salah arah, dan terlihat meyakinkan.
 *   2. TANDA MENGIKUTI ARAH NORMAL AKUN. Pendapatan yang tumbuh harus terbaca NAIK. Tanpa
 *      pembalikan tanda, pendapatan yang membaik tampil sebagai "penurunan terdalam" —
 *      persis kebalikan dari keadaannya, dan tidak ada angka yang terlihat aneh.
 *   3. TIGA LAPORAN, TIGA ARTI "KENAIKAN". Neraca dibanding AKHIR TAHUN LALU (saldo kumulatif
 *      pada satu tanggal); Laba Rugi dibanding RENTANG YANG SAMA setahun lalu (aktivitas);
 *      LR vs Budget dibanding ANGGARAN. Memakai pembanding yang keliru menghasilkan angka
 *      yang tetap masuk akal dilihat sekilas tetapi menjawab pertanyaan yang lain.
 *   4. NAIK DARI NOL BUKAN "NAIK SEKIAN PERSEN". Persentasenya null dan ditandai "baru",
 *      supaya tidak muncul angka tak terhingga atau 100% yang menyesatkan.
 *   5. YANG TIDAK BERGERAK TIDAK IKUT DIANALISA — kalau tidak, daftar penggeraknya penuh
 *      akun bersaldo tetap dan yang benar-benar bergerak terdorong keluar.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-analisa-kenaikan.php';"
 */

use App\Domain\BusinessSupport\Support\AnalisaKenaikan;
use App\Http\Controllers\Web\BusinessSupport\Gl\LedgerReportController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
$rp = fn ($v) => (int) round((float) $v);

$user = User::all()->first(fn ($u) => $u->can('gl.financials.view')) ?? User::first();
if (! $user || ! $user->can('gl.financials.view')) { echo "Tidak ada user berizin gl.financials.view — uji dihentikan.\n"; return; }
auth()->setUser($user);

$ctrl = app(LedgerReportController::class);
$props = function (string $metode, array $q) use ($ctrl, $user): array {
    $r = Request::create('/x', 'GET', $q, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);
    app()->instance('request', $r);

    return $ctrl->{$metode}($r)->toResponse($r)->getData(true)['props'];
};

echo "\n=== 1. ATURAN PERINGKAT (tanpa basis data) ===\n";

/* Inti nomor 1: gajah 20 juta (+15%) harus mengalahkan semut 200 ribu (+200%). */
$a = AnalisaKenaikan::susun([
    ['kode' => 'A', 'nama' => 'Gajah',  'kini' => 153_000_000, 'lalu' => 133_000_000],
    ['kode' => 'B', 'nama' => 'Semut',  'kini' => 300_000,     'lalu' => 100_000],
    ['kode' => 'C', 'nama' => 'Diam',   'kini' => 5_000_000,   'lalu' => 5_000_000],
    /* Susut yang DANGKAL ditulis LEBIH DULU daripada yang dalam. Urutannya sengaja terbalik
       dari hasil yang diharapkan: kalau data ujinya sudah urut sejak awal, menghapus
       pengurutannya tidak mengubah apa pun dan ujinya lulus tanpa menguji apa-apa. */
    ['kode' => 'F', 'nama' => 'Susut Kecil', 'kini' => 4_000_000, 'lalu' => 5_000_000],
    ['kode' => 'D', 'nama' => 'Susut',  'kini' => 1_000_000,   'lalu' => 5_000_000],
    ['kode' => 'E', 'nama' => 'Lahir',  'kini' => 7_000_000,   'lalu' => 0],
    /* Riak di bawah satu sen. Selisihnya dibulatkan ke dua angka di belakang koma lebih dulu,
       jadi pergerakan sekecil ini menjadi nol dan tidak pernah muncul sebagai kenaikan. */
    ['kode' => 'G', 'nama' => 'Debu',   'kini' => 5_000_000.002, 'lalu' => 5_000_000],
], 'Sekarang', 'Dulu');

$ok('diurut menurut rupiah, bukan persentase', ($a['naik'][0]['nama'] ?? '') === 'Gajah',
    'teratas: '.($a['naik'][0]['nama'] ?? '?').' — persentase Semut +200% mengalahkan rupiah');
$ok('semut tetap ikut, hanya tidak di puncak', ($a['naik'][2]['nama'] ?? '') === 'Semut',
    implode(', ', array_column($a['naik'], 'nama')));
/* Inti nomor 5. */
$semua = array_merge(array_column($a['naik'], 'nama'), array_column($a['turun'], 'nama'));
$ok('akun yang tidak bergerak tidak ikut sama sekali', ! in_array('Diam', $semua, true));
$ok('pergerakan di bawah satu sen tidak muncul sebagai kenaikan',
    ! in_array('Debu', $semua, true) && $a['akun_naik'] === 3, implode(', ', $semua));
$ok('yang turun dipisahkan ke arah sebaliknya', ($a['turun'][0]['nama'] ?? '') === 'Susut' && $rp($a['turun'][0]['delta']) === -4_000_000,
    json_encode([$a['turun'][0]['nama'] ?? '?', $rp($a['turun'][0]['delta'] ?? 0)]));
$ok('yang turun diurut dari yang TERDALAM, bukan sekadar dikumpulkan',
    array_column($a['turun'], 'nama') === ['Susut', 'Susut Kecil'], implode(', ', array_column($a['turun'], 'nama')));
/* Inti nomor 4. */
$ok('naik dari nol ditandai "baru", persentasenya kosong',
    ($a['naik'][1]['nama'] ?? '') === 'Lahir' && $a['naik'][1]['baru'] === true && $a['naik'][1]['pct'] === null,
    json_encode([$a['naik'][1]['nama'] ?? '?', $a['naik'][1]['pct'] ?? 'null']));
$ok('persentase dihitung dari pembandingnya', $a['naik'][0]['pct'] === 15.0, (string) $a['naik'][0]['pct']);
$ok('jumlah naik, turun & netto', $rp($a['jumlah_naik']) === 27_200_000 && $rp($a['jumlah_turun']) === -5_000_000 && $rp($a['netto']) === 22_200_000,
    json_encode([$rp($a['jumlah_naik']), $rp($a['jumlah_turun']), $rp($a['netto'])]));
$ok('banyaknya akun yang naik & turun dilaporkan', $a['akun_naik'] === 3 && $a['akun_turun'] === 2,
    json_encode([$a['akun_naik'], $a['akun_turun']]));
$ok('label pembanding dibawa apa adanya', $a['label_kini'] === 'Sekarang' && $a['label_lalu'] === 'Dulu');

/* Keterpusatan: bila satu akun menguasai kenaikannya, itu harus terlihat. */
$pusat = AnalisaKenaikan::susun([
    ['nama' => 'Raksasa', 'kini' => 100_000_000, 'lalu' => 0],
    ['nama' => 'Remah',   'kini' => 1_000_000,   'lalu' => 0],
], 'Kini', 'Lalu');
$ok('keterpusatan dilaporkan sebagai persentase', $pusat['pusat_pct'] === 100.0, (string) ($pusat['pusat_pct'] ?? 'null'));

$banyak = [];
for ($i = 1; $i <= 20; $i++) { $banyak[] = ['nama' => 'Akun '.$i, 'kini' => 1_000_000 * $i, 'lalu' => 0]; }
$b = AnalisaKenaikan::susun($banyak, 'Kini', 'Lalu');
$ok('hanya lima teratas yang disebut namanya', count($b['naik']) === 5, (string) count($b['naik']));
$ok('tetapi jumlah & cacahnya dihitung dari SELURUHNYA',
    $rp($b['jumlah_naik']) === 210_000_000 && $b['akun_naik'] === 20,
    json_encode([$rp($b['jumlah_naik']), $b['akun_naik']]));
// Lima teratas dari 1..20 juta adalah 20+19+18+17+16 = 90 juta, dari seluruhnya 210 juta.
$ok('keterpusatan dihitung terhadap seluruh kenaikan, bukan terhadap lima itu saja',
    $b['pusat_pct'] === round(90 / 210 * 100, 1), (string) $b['pusat_pct']);

$kosong = AnalisaKenaikan::susun([], 'Kini', 'Lalu');
$ok('tanpa pergerakan, hasilnya kosong dan tidak meledak',
    $kosong['naik'] === [] && $kosong['turun'] === [] && $kosong['pusat_pct'] === null);

$TA = 2044;   // tahun uji — jauh dari data mana pun
$tenant = tenant_id() ?? default_tenant_id();
$akun = fn (string $type) => DB::table('bs_accounts')->where('tenant_id', $tenant)
    ->where('is_postable', 1)->where('type', $type)->orderBy('code')->first(['id', 'code', 'name']);
$beban  = $akun('expense');
$pend   = $akun('income');
$aset   = $akun('asset');
$hutang = $akun('liability');
$beban2 = DB::table('bs_accounts')->where('tenant_id', $tenant)->where('is_postable', 1)
    ->where('type', 'expense')->where('id', '!=', $beban->id ?? 0)->orderBy('code')->first(['id', 'code', 'name']);
foreach (compact('beban', 'pend', 'aset', 'hutang', 'beban2') as $n => $x) {
    if (! $x) { echo "Akun contoh '$n' tidak ada — uji dihentikan.\n"; return; }
}

DB::beginTransaction();
DB::statement('SET FOREIGN_KEY_CHECKS=0');

try {
    $seq = 0;
    $jurnal = function (string $tgl, array $lines) use (&$seq, $tenant) {
        $seq++;
        $jid = 930000 + $seq;
        DB::table('bs_journals')->insert([
            'id' => $jid, 'tenant_id' => $tenant, 'journal_number' => 'UJI-ANL-'.$seq, 'journal_date' => $tgl,
            'period_year' => (int) substr($tgl, 0, 4), 'period_month' => (int) substr($tgl, 5, 2),
            'source' => 'manual', 'description' => 'Uji analisa', 'status' => 'posted',
            'total_debit' => array_sum(array_column($lines, 1)), 'total_credit' => array_sum(array_column($lines, 2)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $i => $l) {
            DB::table('bs_journal_lines')->insert([
                'id' => $jid * 10 + $i, 'journal_id' => $jid, 'account_id' => $l[0]->id,
                'description' => 'uji', 'debit' => $l[1], 'credit' => $l[2], 'currency' => 'IDR', 'kurs' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    };

    // Tahun pembanding (2043, semester 1).
    $jurnal(($TA - 1).'-03-01', [[$beban, 10_000_000, 0], [$hutang, 0, 10_000_000]]);
    $jurnal(($TA - 1).'-04-01', [[$aset, 100_000_000, 0], [$pend, 0, 100_000_000]]);
    $jurnal(($TA - 1).'-05-01', [[$beban2, 100_000, 0], [$hutang, 0, 100_000]]);

    // Tahun berjalan (2044, semester 1).
    $jurnal("$TA-03-01", [[$beban, 30_000_000, 0], [$hutang, 0, 30_000_000]]);
    $jurnal("$TA-04-01", [[$aset, 250_000_000, 0], [$pend, 0, 250_000_000]]);
    $jurnal("$TA-05-01", [[$beban2, 300_000, 0], [$hutang, 0, 300_000]]);

    /* Jurnal yang menempel pada akun BUKAN tingkat 6 (Ledger). Seharusnya tidak pernah terjadi
       — bagan akun hanya mengizinkan akun postable — tetapi data warisan atau perbaikan manual
       bisa membuatnya ada. Yang dijaga: akun begitu tidak boleh muncul sebagai "penggerak",
       karena namanya nama kelompok, bukan pos yang bisa ditindaklanjuti siapa pun. */
    $ledger = DB::table('bs_accounts')->where('tenant_id', $tenant)->where('level', 3)
        ->where('code', 'like', '5%')->orderBy('code')->first(['id', 'code', 'name']);
    if ($ledger) {
        $jurnal("$TA-06-01", [[$ledger, 77_000_000, 0], [$hutang, 0, 77_000_000]]);
    }

    echo "\n=== 2. LABA RUGI — dibanding rentang yang sama setahun lalu ===\n";

    $lr = $props('incomeStatement', ['date_from' => "$TA-01-01", 'date_to' => "$TA-06-30"]);
    $an = $lr['analisa'];
    $cari = fn (array $daftar, string $kode) => collect($daftar)->firstWhere('kode', $kode);

    $ok('pembandingnya disebut & benar tahunnya',
        $an['label_lalu'] === 'Periode sama '.($TA - 1) && $an['label_kini'] === 'Periode ini',
        $an['label_lalu']);
    /* Inti nomor 2: pendapatan yang tumbuh WAJIB terbaca naik, bukan turun. */
    $p = $cari($an['naik'], $pend->code);
    $ok('pendapatan yang tumbuh terbaca NAIK, bukan turun',
        $p !== null && $rp($p['delta']) === 150_000_000, $p === null ? 'tidak ada di daftar naik' : (string) $rp($p['delta']));
    $ok('pendapatan jadi penggerak teratas (150jt mengalahkan 20jt)',
        ($an['naik'][0]['kode'] ?? '') === $pend->code, (string) ($an['naik'][0]['nama'] ?? '?'));
    $bb = $cari($an['naik'], $beban->code);
    $ok('beban yang membesar juga terbaca naik', $bb !== null && $rp($bb['delta']) === 20_000_000,
        $bb === null ? 'tidak ada' : (string) $rp($bb['delta']));
    /* Inti nomor 1 di data sungguhan. */
    $kecil = $cari($an['naik'], $beban2->code);
    $ok('akun kecil yang naik 200% tetap di bawah akun besar yang naik 15%',
        // (float) — angkanya sudah lewat JSON, jadi 200.0 pulang sebagai int 200.
        $kecil !== null && $rp($kecil['delta']) === 200_000 && (float) $kecil['pct'] === 200.0
        && array_search($beban2->code, array_column($an['naik'], 'kode'), true) > 0,
        json_encode([$kecil['delta'] ?? null, $kecil['pct'] ?? null]));
    $ok('nama induk Ledger ikut dibawa sebagai keterangan',
        ($an['naik'][0]['grup'] ?? '') !== '', 'grup kosong');
    $ok('kode akun 11 digit ikut dibawa', strlen((string) ($an['naik'][0]['kode'] ?? '')) === 11,
        (string) ($an['naik'][0]['kode'] ?? '?'));
    /* Laporan di atasnya menjumlah menurut awalan kode tanpa memeriksa level, jadi jurnal yang
       menempel pada akun Ledger IKUT terhitung di totalnya. Analisanya harus ikut memuatnya —
       kalau disaring, angkanya tidak lagi bisa dicocokkan dengan laporannya, dan putusnya tidak
       meninggalkan tanda apa pun. Lebih baik ia muncul sebagai penggerak dan terlihat. */
    $ok('jurnal pada akun Ledger ikut tampil sebagai penggerak, bukan disaring diam-diam',
        $ledger === null || $cari($an['naik'], $ledger->code) !== null,
        'akun Ledger '.($ledger->code ?? '').' hilang dari daftar — analisanya tidak lagi cocok dengan laporannya');

    echo "\n=== 3. NERACA — dibanding akhir tahun sebelumnya ===\n";

    $nr = $props('balanceSheet', ['as_of' => "$TA-06-30"]);
    $ann = $nr['analisa'];
    $ok('pembandingnya akhir tahun sebelumnya', str_contains($ann['label_lalu'], (string) ($TA - 1)) && str_contains($ann['label_lalu'], 'Des'),
        $ann['label_lalu']);
    $ak = $cari($ann['naik'], $aset->code);
    /* Aset bertambah 250jt SEPANJANG 2044 saja — 100jt dari 2043 sudah ada di kedua sisi
       pembanding, jadi tidak boleh ikut terhitung sebagai kenaikan tahun ini. */
    $ok('kenaikan aset hanya sepanjang tahun berjalan (250jt, bukan 350jt)',
        $ak !== null && $rp($ak['delta']) === 250_000_000, $ak === null ? 'tidak ada' : (string) $rp($ak['delta']));
    $hk = $cari($ann['naik'], $hutang->code);
    /* Inti nomor 2 pada sisi pasiva: hutang yang membesar harus terbaca NAIK. */
    // 30jt + 300rb + 77jt (lawan jurnal akun Ledger) = 107,3jt.
    $ok('hutang yang membesar terbaca NAIK, bukan turun',
        $hk !== null && $rp($hk['delta']) === 107_300_000, $hk === null ? 'tidak ada di daftar naik' : (string) $rp($hk['delta']));
    $ok('akun laba rugi tidak ikut masuk analisa neraca',
        $cari($ann['naik'], $beban->code) === null && $cari($ann['turun'], $beban->code) === null,
        'akun beban ikut masuk');
    $ok('akun neraca tidak ikut masuk analisa laba rugi',
        $cari($an['naik'], $aset->code) === null && $cari($an['turun'], $aset->code) === null,
        'akun aset ikut masuk ke laba rugi');

    echo "\n=== 4. LABA RUGI vs BUDGET — dibanding anggaran ===\n";

    DB::table('bs_budgets')->insert([
        'id' => 920001, 'tenant_id' => $tenant, 'doc_no' => 'UJI-ANL-BUD', 'year' => $TA,
        'cost_center_id' => null, 'account_id' => $beban->id, 'amount' => 12_000_000, 'status' => 'approved',
        'created_at' => now(), 'updated_at' => now(),
    ] + array_combine(array_map(fn ($m) => 'm'.$m, range(1, 12)), array_fill(0, 12, 1_000_000)));

    $vb = $props('incomeVsBudget', ['date_from' => "$TA-01-01", 'date_to' => "$TA-06-30"]);
    $anb = $vb['analisa'];
    $ok('pembandingnya anggaran, bukan periode lain',
        $anb['label_kini'] === 'Realisasi' && $anb['label_lalu'] === 'Anggaran',
        $anb['label_kini'].' vs '.$anb['label_lalu']);
    $lb = $cari($anb['naik'], $beban->code);
    /* Realisasi 30jt vs anggaran separuh tahun 6jt → melampaui 24jt. */
    $ok('akun yang melampaui anggaran terbaca sebagai kenaikan (30jt − 6jt)',
        $lb !== null && $rp($lb['delta']) === 24_000_000, $lb === null ? 'tidak ada' : (string) $rp($lb['delta']));
    $ok('anggaran yang diprorata dipakai apa adanya, bukan setahun penuh',
        $lb !== null && $rp($lb['lalu']) === 6_000_000, $lb === null ? 'tidak ada' : (string) $rp($lb['lalu']));

    echo "\n=== 5. ANGKA ANALISA SEJALAN DENGAN LAPORANNYA ===\n";

    /* Analisa yang angkanya tidak bisa dicocokkan dengan laporan di atasnya hanya menambah satu
       sumber kebenaran baru. Yang HARUS cocok adalah DAMPAK KE LABA — bukan jumlah kenaikannya.
       Pendapatan naik 150jt dan beban naik 20,2jt dua-duanya "naik" pada akunnya masing-masing
       (jumlah kenaikan 170,2jt), tetapi bagi laba yang satu menambah dan yang satu menggerus,
       sehingga labanya hanya naik 129,8jt. Menyamakan keduanya menghasilkan angka yang terdengar
       masuk akal dan meleset 40 juta. */
    $lrLalu = $props('incomeStatement', ['date_from' => ($TA - 1).'-01-01', 'date_to' => ($TA - 1).'-06-30']);
    $selisihLaba = $rp($lr['net_profit']) - $rp($lrLalu['net_profit']);
    $ok('dampak ke laba sama persis dengan selisih laba netto kedua periodenya',
        $rp($an['dampak_laba']) === $selisihLaba, $rp($an['dampak_laba'] ?? 0).' vs '.$selisihLaba);
    // Jumlah kenaikan = 150jt pendapatan + 20jt beban + 200rb beban kecil + 77jt akun Ledger.
    // Dampak ke laba = 150 − 20 − 0,2 − 77 = 52,8jt. Keduanya memang bukan angka yang sama.
    $ok('jumlah kenaikan memang BERBEDA dari dampak ke laba, bukan kebetulan sama',
        $rp($an['netto']) !== $rp($an['dampak_laba']) && $rp($an['netto']) === 247_200_000,
        $rp($an['netto']).' vs '.$rp($an['dampak_laba'] ?? 0));
    /* Neraca tidak menyentuh laba, jadi tidak boleh mengarang angka dampaknya. */
    $ok('analisa Neraca tidak melaporkan dampak ke laba',
        array_key_exists('dampak_laba', $ann) && $ann['dampak_laba'] === null,
        json_encode($ann['dampak_laba'] ?? 'tidak ada kuncinya'));
    $ok('analisa LR vs Budget melaporkan dampak selisihnya ke laba',
        ($anb['dampak_laba'] ?? null) !== null, 'tidak dilaporkan');
} finally {
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    DB::rollBack();
}

echo "\n────────────────────────────────\n";
echo "LULUS: $lulus   GAGAL: $gagal\n";
echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
