<?php

/**
 * REPORT BUDGET — anggaran vs realisasi per cost center.
 *
 * APA YANG DIJAGA DAN KENAPA.
 *   1. REALISASI DIHITUNG SEKALI PER (COST CENTER + AKUN). Bila satu pasangan punya dua dokumen
 *      anggaran, anggarannya dijumlahkan tetapi realisasinya TIDAK. Menghitung per dokumen
 *      membuat realisasi yang sama terhitung dua kali — dan hasilnya tetap terlihat wajar,
 *      jadi tidak ada yang memberi tahu.
 *   2. ANGKANYA SAMA DENGAN YANG DIPAKAI GERBANG OVER-BUDGET. perCostCenter() mengerjakan
 *      seluruh pasangan sekaligus demi kecepatan; uji ini membandingkannya satu per satu dengan
 *      total() — sumber angka yang dipakai Budget Control & gerbang over-budget — bukan
 *      mengandaikan keduanya sama.
 *   3. BELANJA TANPA ANGGARAN IKUT TERLIHAT. Akun yang punya realisasi tanpa anggaran justru
 *      yang paling perlu tampil. Bila disaring, laporan "sisa anggaran" selalu terlihat sehat.
 *   4. COST CENTER EFEKTIF. Baris → fallback header, KECUALI akun cost_center_optional yang
 *      hanya dihitung bila cost center barisnya memang diisi. Salah di sini memindahkan biaya
 *      ke cost center yang tidak mengeluarkannya.
 *   5. PEMOTONGAN BULAN MEMOTONG KEDUANYA. Anggaran setahun penuh dibanding realisasi separuh
 *      tahun selalu terlihat aman padahal belum tentu.
 *   6. BLOK LINTAS COST CENTER TIDAK IKUT TOTAL. Realisasinya dihitung lintas cost center,
 *      jadi angkanya sudah ada di blok-blok di atasnya.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-report-budget.php';"
 */

use App\Domain\BusinessSupport\Services\BudgetRealizationService;
use App\Http\Controllers\Web\BusinessSupport\Budget\BudgetQueryController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
$rp  = fn ($v) => (int) round((float) $v);
$sat = fn ($v) => round((float) $v, 1);

$user = User::all()->first(fn ($u) => $u->can('budget.query.realization')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
if (! $user->can('budget.query.realization')) { echo "User tanpa izin budget.query.realization — uji dihentikan.\n"; return; }
auth()->setUser($user);

$ctrl = app(BudgetQueryController::class);
$svc  = app(BudgetRealizationService::class);

$props = function (array $q = []) use ($ctrl, $user): array {
    $r = Request::create('/x', 'GET', $q, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);
    app()->instance('request', $r);

    return $ctrl->report($r)->toResponse($r)->getData(true)['props'];
};
/** Cari satu blok menurut kuncinya. */
$blok = fn (array $p, string $key) => collect($p['sections'])->firstWhere('key', $key);
/** Cari satu baris akun di dalam blok. */
$baris = fn (?array $b, string $code) => $b ? collect($b['rows'])->firstWhere('code', $code) : null;

$TAHUN  = 2047;   // tahun yang belum dipakai data mana pun, agar angkanya bisa ditegaskan mutlak
$tenant = tenant_id() ?? default_tenant_id();

$pilihAkun = function (string $type, int $optional) use ($tenant) {
    return DB::table('bs_accounts')->where('tenant_id', $tenant)->where('is_postable', 1)
        ->where('type', $type)->where('cost_center_optional', $optional)
        ->orderBy('code')->first(['id', 'code', 'name']);
};
$beban1 = $pilihAkun('expense', 0);
$beban2 = DB::table('bs_accounts')->where('tenant_id', $tenant)->where('is_postable', 1)
    ->where('type', 'expense')->where('cost_center_optional', 0)->where('id', '!=', $beban1->id ?? 0)
    ->orderBy('code')->first(['id', 'code', 'name']);
$beban3 = DB::table('bs_accounts')->where('tenant_id', $tenant)->where('is_postable', 1)
    ->where('type', 'expense')->where('cost_center_optional', 0)->whereNotIn('id', [$beban1->id ?? 0, $beban2->id ?? 0])
    ->orderBy('code')->first(['id', 'code', 'name']);
$pend   = $pilihAkun('income', 0);
$hutang = $pilihAkun('liability', 0);
$kas    = $pilihAkun('asset', 0);
$opsi   = $pilihAkun('expense', 1);
foreach (['beban1' => $beban1, 'beban2' => $beban2, 'beban3' => $beban3, 'pend' => $pend, 'hutang' => $hutang, 'kas' => $kas, 'opsi' => $opsi] as $n => $a) {
    if (! $a) { echo "Akun contoh '$n' tidak ada — uji dihentikan.\n"; return; }
}

$ccA = DB::table('bs_cost_centers')->where('tenant_id', $tenant)->whereNull('deleted_at')->orderBy('code')->first(['id', 'code', 'name']);
$ccB = DB::table('bs_cost_centers')->where('tenant_id', $tenant)->whereNull('deleted_at')->where('id', '!=', $ccA->id ?? 0)->orderBy('code')->first(['id', 'code', 'name']);
if (! $ccA || ! $ccB) { echo "Perlu minimal dua cost center — uji dihentikan.\n"; return; }

DB::beginTransaction();
DB::statement('SET FOREIGN_KEY_CHECKS=0');

try {
    $seqB = 0;
    $anggaran = function ($akun, ?int $ccId, float $perBulan, string $status = 'approved') use (&$seqB, $tenant, $TAHUN): void {
        $seqB++;
        $bulan = [];
        for ($m = 1; $m <= 12; $m++) { $bulan['m'.$m] = $perBulan; }
        DB::table('bs_budgets')->insert([
            'id' => 960000 + $seqB, 'tenant_id' => $tenant, 'doc_no' => 'UJI-BC-'.str_pad((string) $seqB, 3, '0', STR_PAD_LEFT),
            'year' => $TAHUN, 'cost_center_id' => $ccId, 'account_id' => $akun->id,
            'amount' => round($perBulan * 12, 2), 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ] + $bulan);
    };

    $seqJ = 0;
    /** @param array<int,array{0:object,1:?int,2:float,3:float}> $lines [akun, cost center baris, debit, kredit] */
    $jurnal = function (string $tgl, string $status, ?int $headerCc, array $lines) use (&$seqJ, $tenant, $TAHUN): void {
        $seqJ++;
        $jid = 950000 + $seqJ;
        $d = array_sum(array_map(fn ($l) => $l[2], $lines));
        $k = array_sum(array_map(fn ($l) => $l[3], $lines));
        DB::table('bs_journals')->insert([
            'id' => $jid, 'tenant_id' => $tenant, 'cost_center_id' => $headerCc,
            'journal_number' => 'UJI-JV-'.str_pad((string) $seqJ, 3, '0', STR_PAD_LEFT), 'journal_date' => $tgl,
            'period_year' => $TAHUN, 'period_month' => (int) substr($tgl, 5, 2), 'source' => 'manual',
            'description' => 'Uji Report Budget', 'status' => $status,
            'total_debit' => $d, 'total_credit' => $k, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $i => $l) {
            DB::table('bs_journal_lines')->insert([
                'id' => $jid * 10 + $i, 'journal_id' => $jid, 'account_id' => $l[0]->id, 'cost_center_id' => $l[1],
                'description' => 'uji', 'debit' => $l[2], 'credit' => $l[3], 'currency' => 'IDR', 'kurs' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    };

    // ── Anggaran ──
    $anggaran($beban1, $ccA->id, 10_000_000);           // 120.000.000 setahun
    $anggaran($beban2, $ccA->id, 2_500_000);            //  30.000.000
    $anggaran($pend,   $ccA->id, 5_000_000);            //  60.000.000
    $anggaran($beban1, $ccB->id, 3_000_000);            //  36.000.000
    /* Indeks unik bs_budget_unique melarang dua dokumen untuk (cost center + akun) yang sama —
       KECUALI bila cost center-nya kosong, karena MySQL menganggap NULL selalu berbeda. Di
       situlah penjumlahan anggaran & "realisasi dihitung sekali" benar-benar bisa terjadi. */
    $anggaran($beban1, null,     40_000_000);           // 480.000.000 ┐ dua dokumen lintas cost
    $anggaran($beban1, null,     10_000_000);           // 120.000.000 ┘ center, akun yang SAMA
    $anggaran($beban3, $ccB->id, 0);                    // nol & tanpa realisasi → tidak ditampilkan
    $anggaran($beban2, $ccB->id, 64_750_000, 'submitted'); // belum disetujui → tidak dihitung

    // ── Jurnal ──
    $jurnal("$TAHUN-03-10", 'posted', null,     [[$beban1, $ccA->id, 25_000_000, 0], [$hutang, null, 0, 25_000_000]]);
    $jurnal("$TAHUN-04-15", 'final',  $ccA->id, [[$beban2, null, 40_000_000, 0], [$hutang, null, 0, 40_000_000]]);
    $jurnal("$TAHUN-05-20", 'draft',  null,     [[$beban3, $ccA->id, 7_000_000, 0], [$hutang, null, 0, 7_000_000]]);
    $jurnal("$TAHUN-06-25", 'posted', null,     [[$kas, null, 90_000_000, 0], [$pend, $ccA->id, 0, 90_000_000]]);
    $jurnal("$TAHUN-11-05", 'posted', $ccB->id, [[$beban1, $ccB->id, 50_000_000, 0], [$hutang, null, 0, 50_000_000]]);
    $jurnal("$TAHUN-07-08", 'posted', $ccA->id, [[$opsi, null, 13_000_000, 0], [$hutang, null, 0, 13_000_000]]);
    $jurnal("$TAHUN-08-12", 'void',   null,     [[$beban1, $ccA->id, 999_000_000, 0], [$hutang, null, 0, 999_000_000]]);
    $jurnal("$TAHUN-12-20", 'posted', null,     [[$beban1, $ccA->id, 11_000_000, 0], [$hutang, null, 0, 11_000_000]]);

    $p = $props(['year' => $TAHUN]);
    $bA = $blok($p, (string) $ccA->id);
    $bB = $blok($p, (string) $ccB->id);
    $b0 = $blok($p, '0');
    $bS = $blok($p, 'semua');

    echo "\n=== 1. BLOK PER COST CENTER & URUTANNYA ===\n";

    $ok('ada empat blok: dua cost center, tanpa cost center, lintas cost center',
        count($p['sections']) === 4, count($p['sections']).' blok: '.implode(', ', array_column($p['sections'], 'key')));
    $ok('blok cost center diurutkan menurut kode',
        array_slice(array_column($p['sections'], 'key'), 0, 2) === [(string) $ccA->id, (string) $ccB->id],
        implode(', ', array_column($p['sections'], 'key')));
    /* "Tanpa" & "Semua" adalah keranjang sisa, bukan cost center — tempatnya di bawah, setelah
       cost center yang sebenarnya, supaya tidak menyela urutan kode. */
    $ok('tanpa cost center lalu lintas cost center di dua posisi terakhir',
        array_slice(array_column($p['sections'], 'key'), -2) === ['0', 'semua'],
        implode(', ', array_column($p['sections'], 'key')));
    $ok('blok memakai nama cost center-nya', ($bA['name'] ?? '') === $ccA->name, (string) ($bA['name'] ?? '?'));
    $ok('blok tanpa cost center diberi nama yang menjelaskan', ($b0['name'] ?? '') === 'Tanpa Cost Center', (string) ($b0['name'] ?? '?'));
    $ok('blok lintas cost center ditandai lintas', ($bS['lintas'] ?? null) === true);
    $ok('blok cost center biasa TIDAK ditandai lintas', ($bA['lintas'] ?? null) === false);

    echo "\n=== 2. DUA DOKUMEN ANGGARAN → SATU BARIS, REALISASI TIDAK GANDA ===\n";

    $r2 = $baris($bA, $beban2->code);
    $rS = $baris($bS, $beban1->code);
    $ok('akun dengan dua dokumen anggaran hanya muncul SEKALI',
        collect($bS['rows'] ?? [])->where('code', $beban1->code)->count() === 1,
        (string) collect($bS['rows'] ?? [])->where('code', $beban1->code)->count().' baris');
    $ok('anggaran kedua dokumen dijumlahkan (480jt + 120jt)', $rp($rS['budget'] ?? 0) === 600_000_000, (string) $rp($rS['budget'] ?? 0));
    /* Inti nomor 1: bila realisasi dihitung per dokumen, angkanya jadi 172jt. */
    $ok('realisasi TETAP 86jt, bukan dihitung dua kali', $rp($rS['real'] ?? 0) === 86_000_000, (string) $rp($rS['real'] ?? 0));
    $ok('akun dengan satu dokumen anggaran tetap utuh (30jt vs realisasi 40jt)',
        $rp($r2['budget'] ?? 0) === 30_000_000 && $rp($r2['real'] ?? 0) === 40_000_000,
        json_encode([$rp($r2['budget'] ?? 0), $rp($r2['real'] ?? 0)]));

    echo "\n=== 3. BELANJA TANPA ANGGARAN & RUMUS SISA/ABSORPSI ===\n";

    $r3 = $baris($bA, $beban3->code);
    $ok('akun berealisasi tanpa anggaran tetap tampil', $r3 !== null, 'baris tidak ada');
    $ok('anggarannya nol, sisanya negatif sebesar realisasinya',
        $rp($r3['budget'] ?? -1) === 0 && $rp($r3['sisa'] ?? 0) === -7_000_000,
        json_encode([$rp($r3['budget'] ?? -1), $rp($r3['sisa'] ?? 0)]));
    $ok('% sisa −100% — seluruhnya di luar anggaran', $sat($r3['pct_sisa'] ?? 0) === -100.0, (string) ($r3['pct_sisa'] ?? '?'));
    /* Pembagian dengan nol bukan "0% terpakai": batang penuh-kosong terbaca aman padahal
       justru tidak ada anggarannya sama sekali. */
    $ok('absorpsi null (layar menulisnya "—"), bukan 0', array_key_exists('absorpsi', $r3 ?? []) && $r3['absorpsi'] === null,
        json_encode($r3['absorpsi'] ?? 'tidak ada'));

    $r1 = $baris($bA, $beban1->code);
    $ok('sisa = anggaran − realisasi (120jt − 36jt)', $rp($r1['sisa'] ?? 0) === 84_000_000, (string) $rp($r1['sisa'] ?? 0));
    $ok('% sisa = sisa / anggaran (84/120)', $sat($r1['pct_sisa'] ?? 0) === 70.0, (string) ($r1['pct_sisa'] ?? '?'));
    $ok('absorpsi = realisasi / anggaran (36/120)', $sat($r1['absorpsi'] ?? -1) === 30.0, (string) ($r1['absorpsi'] ?? '?'));

    $rp2 = $baris($bA, $pend->code);
    $ok('melewati anggaran → sisa negatif & absorpsi di atas 100%',
        $rp($rp2['sisa'] ?? 0) === -30_000_000 && $sat($rp2['absorpsi'] ?? 0) === 150.0,
        json_encode([$rp($rp2['sisa'] ?? 0), $rp2['absorpsi'] ?? '?']));

    echo "\n=== 4. TANDA MENGIKUTI JENIS AKUN ===\n";

    /* Akun pendapatan bertanda kredit: KREDIT 90jt menambah realisasi, bukan menguranginya.
       Salah tanda membuat pendapatan terbaca −90jt dan "sisa"-nya melonjak. */
    $ok('kredit pada akun pendapatan MENAMBAH realisasi', $rp($rp2['real'] ?? 0) === 90_000_000, (string) $rp($rp2['real'] ?? 0));
    $ok('debit pada akun beban menambah realisasi (25jt + 11jt)', $rp($r1['real'] ?? 0) === 36_000_000, (string) $rp($r1['real'] ?? 0));

    echo "\n=== 5. COST CENTER EFEKTIF: BARIS → HEADER, KECUALI AKUN OPSIONAL ===\n";

    /* Baris jurnal 2 tidak punya cost center; yang dipakai adalah cost center header. */
    $ok('baris tanpa cost center memakai cost center header', $r2 !== null && $rp($r2['real']) === 40_000_000, 'tidak masuk blok header');
    $rOpsiA = $baris($bA, $opsi->code);
    $rOpsi0 = $baris($b0, $opsi->code);
    /* Akun cost_center_optional sengaja BOLEH dijurnal tanpa cost center. Menariknya ke cost
       center header berarti membebankan biaya ke cost center yang tidak mengeluarkannya. */
    $ok('akun cost_center_optional TIDAK ditarik ke cost center header', $rOpsiA === null, 'malah masuk blok header');
    $ok('akun cost_center_optional masuk blok Tanpa Cost Center', $rOpsi0 !== null && $rp($rOpsi0['real']) === 13_000_000,
        $rOpsi0 ? (string) $rp($rOpsi0['real']) : 'baris tidak ada');
    $ok('total() pun menganggapnya bukan milik cost center header',
        $rp($svc->total($TAHUN, (int) $opsi->id, (int) $ccA->id)) === 0, (string) $rp($svc->total($TAHUN, (int) $opsi->id, (int) $ccA->id)));

    /* Akun yang sama dipakai dua cost center harus terpisah, masing-masing hanya miliknya. */
    $r1B = $baris($bB, $beban1->code);
    $ok('akun yang sama di dua cost center dipisah menurut cost center-nya',
        $rp($r1['real'] ?? 0) === 36_000_000 && $rp($r1B['real'] ?? 0) === 50_000_000,
        json_encode([$rp($r1['real'] ?? 0), $rp($r1B['real'] ?? 0)]));

    echo "\n=== 6. BLOK LINTAS COST CENTER TIDAK IKUT TOTAL ===\n";

    /* Anggaran tanpa cost center adalah plafon lintas cost center — begitu pula realisasinya,
       persis seperti yang dipakai gerbang over-budget: 36jt (A) + 50jt (B). */
    $ok('realisasi blok lintas dihitung lintas cost center (36jt + 50jt)',
        $rp($rS['real'] ?? 0) === 86_000_000, (string) $rp($rS['real'] ?? 0));
    /* Kalau ikut dijumlahkan, 86jt yang sama terhitung dua kali dan 600jt anggaran membuat
       "sisa" keseluruhan terlihat besar padahal itu plafon yang sama. */
    $ok('anggaran blok lintas TIDAK masuk total keseluruhan',
        $rp($p['total']['budget']) === 246_000_000, (string) $rp($p['total']['budget']));
    $ok('realisasi blok lintas TIDAK masuk total keseluruhan',
        $rp($p['total']['real']) === 472_000_000, (string) $rp($p['total']['real']));
    $ok('jumlah blok lintas dilaporkan terpisah', (int) $p['total']['lintas'] === 1, (string) $p['total']['lintas']);

    echo "\n=== 7. RINGKASAN BLOK = JUMLAH BARISNYA ===\n";

    foreach ($p['sections'] as $s) {
        $jb = $rp(array_sum(array_column($s['rows'], 'budget')));
        $jr = $rp(array_sum(array_column($s['rows'], 'real')));
        $ok('ringkasan blok '.$s['key'].' sama dengan jumlah barisnya',
            $rp($s['summary']['budget']) === $jb && $rp($s['summary']['real']) === $jr,
            json_encode([$rp($s['summary']['budget']), $jb, $rp($s['summary']['real']), $jr]));
        $ok('jumlah akun blok '.$s['key'].' sama dengan jumlah barisnya',
            (int) $s['summary']['akun'] === count($s['rows']), (string) $s['summary']['akun'].' vs '.count($s['rows']));
    }
    $ok('ringkasan blok A: 210jt anggaran, 226jt realisasi, 5 akun',
        $rp($bA['summary']['budget']) === 210_000_000 && $rp($bA['summary']['real']) === 226_000_000 && (int) $bA['summary']['akun'] === 5,
        json_encode([$rp($bA['summary']['budget']), $rp($bA['summary']['real']), $bA['summary']['akun']]));
    $ok('total akun keseluruhan tidak memuat akun blok lintas (5 + 2 + 3)',
        (int) $p['total']['akun'] === 10, (string) $p['total']['akun']);

    echo "\n=== 8. BARIS & ANGGARAN YANG TIDAK DIHITUNG ===\n";

    $ok('anggaran nol tanpa realisasi tidak ditampilkan', $baris($bB, $beban3->code) === null, 'baris kosong ikut tampil');
    /* Anggaran yang belum disetujui bukan plafon; menampilkannya membuat sisa terlihat ada. */
    $ok('anggaran berstatus submitted tidak dihitung', $baris($bB, $beban2->code) === null, 'anggaran belum disetujui ikut terhitung');
    /* Jurnal batal tidak boleh menyisakan jejak: 999jt akan sangat terlihat bila ikut. */
    $ok('jurnal berstatus di luar draft/final/posted tidak dihitung',
        $rp($r1['real'] ?? 0) === 36_000_000, 'realisasi '.$rp($r1['real'] ?? 0).' — jurnal batal ikut terhitung');
    $ok('jurnal draft & final IKUT dihitung (7jt draft, 40jt final)',
        $rp($r3['real'] ?? 0) === 7_000_000 && $rp($r2['real'] ?? 0) === 40_000_000,
        json_encode([$rp($r3['real'] ?? 0), $rp($r2['real'] ?? 0)]));

    echo "\n=== 9. BARIS DIURUTKAN MENURUT KODE AKUN ===\n";

    foreach ($p['sections'] as $s) {
        $kode = array_column($s['rows'], 'code');
        $urut = $kode;
        sort($urut, SORT_STRING);
        $ok('baris blok '.$s['key'].' urut menurut kode akun', $kode === $urut, implode(' | ', $kode));
    }

    echo "\n=== 10. PEMOTONGAN S/D BULAN MEMOTONG ANGGARAN *DAN* REALISASI ===\n";

    $p6  = $props(['year' => $TAHUN, 'sd_bulan' => 6]);
    $bA6 = $blok($p6, (string) $ccA->id);
    $r16 = $baris($bA6, $beban1->code);
    $ok('realisasi dipotong: jurnal Des & Nov tidak ikut (tinggal 25jt)',
        $rp($r16['real'] ?? 0) === 25_000_000, (string) $rp($r16['real'] ?? 0));
    /* "s/d Jun" berarti sampai AKHIR Juni. Batas yang keliru mengambil tanggal 1 membuang
       hampir seluruh bulan terakhirnya — dan sisanya lalu terlihat jauh lebih longgar.
       Jurnal ini bertanggal 25 Jun: tepat di wilayah yang hilang bila batasnya salah. */
    $ok('jurnal 25 Jun masih ikut — batasnya akhir bulan, bukan awal bulan',
        $rp($baris($bA6, $pend->code)['real'] ?? 0) === 90_000_000, (string) $rp($baris($bA6, $pend->code)['real'] ?? 0));
    /* Inti nomor 5: anggaran setahun (120jt) dibanding realisasi separuh tahun selalu
       terlihat aman. Yang dibandingkan harus anggaran Jan–Jun saja. */
    $ok('anggaran ikut dipotong menjadi Jan–Jun (6 × 10jt)',
        $rp($r16['budget'] ?? 0) === 60_000_000, (string) $rp($r16['budget'] ?? 0));
    $ok('anggaran akun lain ikut dipotong bersama (6 × 2,5jt)',
        $rp($baris($bA6, $beban2->code)['budget'] ?? 0) === 15_000_000, (string) $rp($baris($bA6, $beban2->code)['budget'] ?? 0));
    /* Dua dokumen lintas cost center dipotong lalu dijumlahkan: 6 × (40jt + 10jt). */
    $ok('dua dokumen lintas cost center dipotong lalu dijumlahkan (300jt)',
        $rp($baris($blok($p6, 'semua'), $beban1->code)['budget'] ?? 0) === 300_000_000,
        (string) $rp($baris($blok($p6, 'semua'), $beban1->code)['budget'] ?? 0));
    $ok('blok lintas cost center juga memotong realisasinya (tinggal 25jt)',
        $rp($baris($blok($p6, 'semua'), $beban1->code)['real'] ?? 0) === 25_000_000,
        (string) $rp($baris($blok($p6, 'semua'), $beban1->code)['real'] ?? 0));
    $ok('akun yang belum berjalan & belum dianggarkan s/d Jun ikut hilang',
        $baris($blok($p6, (string) $ccB->id), $hutang->code) === null, 'hutang cost center B masih muncul');
    $ok('s/d Des mengembalikan angka utuh lagi', $rp($baris($blok($props(['year' => $TAHUN, 'sd_bulan' => 12]), (string) $ccA->id), $beban1->code)['budget']) === 120_000_000);

    echo "\n=== 11. FILTER COST CENTER ===\n";

    $pf = $props(['year' => $TAHUN, 'cost_center_id' => $ccB->id]);
    $ok('hanya blok cost center yang dipilih yang tampil',
        array_column($pf['sections'], 'key') === [(string) $ccB->id], implode(', ', array_column($pf['sections'], 'key')));
    $ok('angkanya sama dengan blok itu saat tanpa filter',
        $rp($pf['sections'][0]['summary']['real'] ?? 0) === $rp($bB['summary']['real']),
        json_encode([$rp($pf['sections'][0]['summary']['real'] ?? 0), $rp($bB['summary']['real'])]));
    $ok('blok lintas cost center tidak ikut saat difilter',
        $blok($pf, 'semua') === null, 'blok lintas ikut muncul');

    echo "\n=== 12. perCostCenter() SAMA DENGAN total() ===\n";

    /* Yang dijaga: kedua jalan hitung TIDAK BOLEH berbeda. perCostCenter() dipakai laporan ini
       demi kecepatan, total() dipakai Budget Control & gerbang over-budget. Bila salah satunya
       berubah sendiri, laporan dan gerbang memberi angka berbeda untuk pertanyaan yang sama. */
    $peta  = $svc->perCostCenter($TAHUN);
    $beda  = [];
    $dicek = 0;
    foreach ($p['sections'] as $s) {
        if ($s['lintas'] || $s['key'] === '0') { continue; }
        foreach ($s['rows'] as $row) {
            $accId = (int) DB::table('bs_accounts')->where('tenant_id', $tenant)->where('code', $row['code'])->value('id');
            $dicek++;
            $a = $rp($peta[$s['key'].'|'.$accId] ?? 0);
            $b = $rp($svc->total($TAHUN, $accId, (int) $s['key']));
            if ($a !== $b) { $beda[] = $row['code'].": sekaligus $a vs satuan $b"; }
        }
    }
    $ok('setiap pasangan diperiksa ke dua sumber angka', $dicek === 7, "$dicek pasangan");
    $ok('tidak ada pasangan yang berbeda antara kedua cara hitung', $beda === [], implode(' ; ', $beda));

    $peta6 = $svc->perCostCenter($TAHUN, "$TAHUN-01-01", "$TAHUN-06-30");
    $ok('rentang tanggal pun sama pada kedua cara hitung',
        $rp($peta6[$ccA->id.'|'.$beban1->id] ?? 0) === $rp($svc->total($TAHUN, (int) $beban1->id, (int) $ccA->id, "$TAHUN-01-01", "$TAHUN-06-30")),
        json_encode([$rp($peta6[$ccA->id.'|'.$beban1->id] ?? 0), $rp($svc->total($TAHUN, (int) $beban1->id, (int) $ccA->id, "$TAHUN-01-01", "$TAHUN-06-30"))]));

    echo "\n=== 13. TAHUN LAIN TIDAK TERBAWA ===\n";

    $pl = $props(['year' => $TAHUN + 1]);
    $ok('tahun berikutnya kosong — data uji tidak bocor lintas tahun', $pl['sections'] === [], count($pl['sections']).' blok');
} finally {
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    DB::rollBack();
}

echo "\n────────────────────────────────\n";
echo "LULUS: $lulus   GAGAL: $gagal\n";
echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
