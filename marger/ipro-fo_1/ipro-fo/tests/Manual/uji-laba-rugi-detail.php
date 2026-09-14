<?php

declare(strict_types=1);

/**
 * LAPORAN LABA RUGI — MODE DETAIL (akun tingkat 6).
 *
 * APA YANG DIJAGA DAN KENAPA.
 *   1. MODE DETAIL TIDAK MENGGESER SATU ANGKA PUN. Seluruh baris header/group/subtotal/
 *      subtotal2/emphasis/total harus IDENTIK antara mode ringkas dan detail — label, urutan,
 *      dan nilainya. Laporan keuangan yang angkanya berubah hanya karena tombol tampilan
 *      ditekan tidak bisa dipercaya lagi untuk apa pun.
 *   2. JUMLAH RINCIAN = NILAI BARIS LEDGER-NYA. Dijamin secara konstruksi (aturan awalan kode
 *      yang sama dengan $sum), dan diuji di sini supaya tetap begitu.
 *   3. RINCIAN MENDAHULUI INDUKNYA. Baris Ledger dibaca sebagai JUMLAH dari rincian di atasnya,
 *      sama seperti setiap subtotal lain di laporan ini. Terbalik, ia terbaca seperti judul.
 *   4. TANDA MENGIKUTI INDUK. Rincian akun pendapatan harus positif seperti induknya, bukan
 *      merah berkurung.
 *   5. NODE YANG ANAKNYA SALING MENIADAKAN TETAP TAMPIL di mode detail. Di mode ringkas ia
 *      memang dibuang (nilainya nol), tetapi justru keadaan itulah yang orang cari saat
 *      membuka rincian — membuangnya membuat seluruh kategori lenyap tanpa jejak.
 *   6. SETIAP JENIS BARIS YANG DIPANCARKAN PUNYA CABANG RENDER. Rantai v-if di layar tidak
 *      punya penjaga tipe, jadi jenis baris yang belum dikenal HILANG tanpa error.
 *   7. MODE RINGKAS TIDAK BERUBAH SEDIKIT PUN, termasuk berkas Excel-nya — tidak ada kode akun
 *      yang tiba-tiba muncul di berkas yang selama ini sudah biasa diterima orang.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-laba-rugi-detail.php';"
 */

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
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
if (! $user->can('gl.financials.view')) { echo "User tanpa izin gl.financials.view — uji dihentikan.\n"; return; }
auth()->setUser($user);

$ctrl = app(LedgerReportController::class);

$props = function (array $q) use ($ctrl, $user): array {
    $r = Request::create('/business-support/gl/laba-rugi', 'GET', $q, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);
    app()->instance('request', $r);

    return $ctrl->incomeStatement($r)->toResponse($r)->getData(true)['props'];
};
$xlsx = function (array $q) use ($ctrl, $user): string {
    $r = Request::create('/x', 'GET', $q);
    $r->setUserResolver(fn () => $user);
    app()->instance('request', $r);
    ob_start();
    $ctrl->incomeStatementExport($r)->sendContent();

    return (string) ob_get_clean();
};
/** Ambil <sheetData> dari .xlsx supaya isinya bisa diperiksa sebagai teks. */
$isiXlsx = function (string $biner): string {
    $tmp = tempnam(sys_get_temp_dir(), 'uji').'.xlsx';
    file_put_contents($tmp, $biner);
    $zip = new ZipArchive();
    $teks = $zip->open($tmp) === true ? (string) $zip->getFromName('xl/worksheets/sheet1.xml') : '';
    $bersama = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    @unlink($tmp);

    return $teks.(string) $bersama;
};

$TAHUN  = 2049;   // tahun yang belum dipakai data mana pun
$DARI   = "$TAHUN-01-01";
$SAMPAI = "$TAHUN-12-31";
$tenant = tenant_id() ?? default_tenant_id();

$akun = function (string $code) use ($tenant) {
    return DB::table('bs_accounts')->where('tenant_id', $tenant)->where('code', $code)->first(['id', 'code', 'name']);
};
// Dua akun di bawah SATU node Ledger (50301) supaya "jumlah rincian = nilai induk" benar-benar
// diuji; sepasang yang saling meniadakan sengaja ditaruh di node LAIN (50302) supaya netto node
// itu benar-benar nol — kalau dicampur ke node yang sudah bernilai, keadaan yang mau diuji
// tidak pernah terjadi. Satu akun pendapatan untuk memeriksa tandanya.
$gaji    = $akun('50301010101');
$bpjs    = $akun('50301010102');
$plus    = $akun('50302020201');
$minus   = $akun('50302020202');
$jual    = $akun('40101010101');
foreach (['gaji' => $gaji, 'bpjs' => $bpjs, 'plus' => $plus, 'minus' => $minus, 'jual' => $jual] as $n => $a) {
    if (! $a) { echo "Akun contoh '$n' tidak ada di bagan akun — uji dihentikan.\n"; return; }
}
$kas = DB::table('bs_accounts')->where('tenant_id', $tenant)->where('is_postable', 1)
    ->where('type', 'asset')->orderBy('code')->first(['id', 'code', 'name']);

DB::beginTransaction();
DB::statement('SET FOREIGN_KEY_CHECKS=0');

try {
    $seq = 0;
    $jurnal = function (string $tgl, array $lines) use (&$seq, $tenant, $TAHUN) {
        $seq++;
        $jid = 940000 + $seq;
        DB::table('bs_journals')->insert([
            'id' => $jid, 'tenant_id' => $tenant, 'journal_number' => 'UJI-LRD-'.$seq, 'journal_date' => $tgl,
            'period_year' => $TAHUN, 'period_month' => (int) substr($tgl, 5, 2), 'source' => 'manual',
            'description' => 'Uji Laba Rugi Detail', 'status' => 'posted',
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

    // Beban karyawan: 30jt + 12jt = 42jt netto pada node 50301, ditambah sepasang yang meniadakan.
    $jurnal("$TAHUN-02-10", [[$gaji, 30_000_000, 0], [$kas, 0, 30_000_000]]);
    $jurnal("$TAHUN-03-11", [[$bpjs, 12_000_000, 0], [$kas, 0, 12_000_000]]);
    $jurnal("$TAHUN-04-12", [[$plus, 5_000_000, 0], [$minus, 0, 5_000_000]]);
    // Pendapatan: kredit 200jt — di laporan harus POSITIF, baik induk maupun rinciannya.
    $jurnal("$TAHUN-05-13", [[$kas, 200_000_000, 0], [$jual, 0, 200_000_000]]);

    $ringkas = $props(['date_from' => $DARI, 'date_to' => $SAMPAI]);
    $detail  = $props(['date_from' => $DARI, 'date_to' => $SAMPAI, 'view' => 'detail']);

    $KIND_DIKENAL = ['header', 'group', 'line', 'detail', 'subtotal', 'subtotal2', 'emphasis', 'total'];
    $SKALAR = ['header', 'group', 'subtotal', 'subtotal2', 'emphasis', 'total'];
    $hanya = fn (array $rows, array $kinds) => array_values(array_filter($rows, fn ($r) => in_array($r['kind'], $kinds, true)));

    echo "\n=== 1. MODE DETAIL TIDAK MENGGESER SATU ANGKA PUN ===\n";

    $ok('mode dikembalikan ke layar', ($ringkas['view'] ?? '') === 'summary' && ($detail['view'] ?? '') === 'detail',
        json_encode([$ringkas['view'] ?? '?', $detail['view'] ?? '?']));
    /* Inti nomor 1: seluruh kerangka laporan — judul bagian, subtotal, EBITDA, laba netto —
       harus sama persis. Bila satu saja bergeser, tombol tampilan ikut mengubah angka. */
    $kerangkaR = array_map(fn ($r) => $r['kind'].'|'.$r['label'].'|'.$rp($r['amount']), $hanya($ringkas['rows'], $SKALAR));
    $kerangkaD = array_map(fn ($r) => $r['kind'].'|'.$r['label'].'|'.$rp($r['amount']), $hanya($detail['rows'], $SKALAR));
    $ok('seluruh baris judul, subtotal & total identik antar kedua mode', $kerangkaR === $kerangkaD,
        'beda: '.json_encode(array_values(array_diff($kerangkaD, $kerangkaR))));
    $ok('laba rugi netto identik', $rp($ringkas['net_profit']) === $rp($detail['net_profit']),
        $rp($ringkas['net_profit']).' vs '.$rp($detail['net_profit']));
    $ok('mode ringkas tidak memuat satu pun baris rincian',
        $hanya($ringkas['rows'], ['detail']) === [], count($hanya($ringkas['rows'], ['detail'])).' baris');
    $ok('mode detail memang memuat baris rincian', count($hanya($detail['rows'], ['detail'])) > 0);

    echo "\n=== 2. JUMLAH RINCIAN = NILAI BARIS LEDGER-NYA ===\n";

    /* Baris dibaca berurutan: rincian menumpuk sampai bertemu baris Ledger, lalu dicocokkan.
       Ini sekaligus memastikan urutannya (rincian DULU, baru induknya). */
    $belum = [];
    $cocok = 0;
    $sumbang = [];
    $yatim = 0;
    foreach ($detail['rows'] as $r) {
        if ($r['kind'] === 'detail') { $belum[] = $r; continue; }
        if ($r['kind'] === 'line') {
            $jum = round(array_sum(array_map(fn ($d) => (float) $d['amount'], $belum)), 2);
            if ($belum !== [] && $rp($jum) !== $rp($r['amount'])) {
                $sumbang[] = $r['label'].': Σrincian '.$rp($jum).' vs induk '.$rp($r['amount']);
            }
            if ($belum !== []) { $cocok++; }
            foreach ($belum as $d) {
                // Kode rincian harus benar-benar berada DI BAWAH kode induknya.
                if (! str_starts_with((string) $d['code'], (string) $r['code']) || strlen((string) $d['code']) <= strlen((string) $r['code'])) {
                    $yatim++;
                }
            }
            $belum = [];

            continue;
        }
        $belum = []; // baris lain memutus rangkaian
    }
    $ok('ada baris Ledger yang benar-benar dirinci', $cocok >= 2, "$cocok baris Ledger berrincian");
    $ok('setiap jumlah rincian sama dengan nilai baris Ledger-nya', $sumbang === [], implode(' ; ', $sumbang));
    $ok('setiap kode rincian berada di bawah kode induknya', $yatim === 0, "$yatim baris menyimpang");
    $ok('tidak ada baris rincian yang menggantung tanpa induk', $belum === [], count($belum).' menggantung');

    echo "\n=== 3. RINCIAN MENDAHULUI INDUKNYA ===\n";

    $iDetail = null;
    $iLine = null;
    foreach ($detail['rows'] as $i => $r) {
        if ($iDetail === null && $r['kind'] === 'detail' && (string) $r['code'] === '50301010101') { $iDetail = $i; }
        if ($iDetail !== null && $iLine === null && $r['kind'] === 'line' && (string) $r['code'] === '50301') { $iLine = $i; }
    }
    $ok('rincian BEBAN KARYAWAN muncul sebelum baris Ledger-nya',
        $iDetail !== null && $iLine !== null && $iDetail < $iLine, json_encode([$iDetail, $iLine]));

    echo "\n=== 4. ANGKA RINCIAN & TANDANYA ===\n";

    $cari = fn (array $rows, string $kind, string $code) => collect($rows)->first(fn ($r) => $r['kind'] === $kind && (string) ($r['code'] ?? '') === $code);
    $ok('rincian gaji 30jt', $rp($cari($detail['rows'], 'detail', '50301010101')['amount'] ?? 0) === 30_000_000,
        (string) $rp($cari($detail['rows'], 'detail', '50301010101')['amount'] ?? 0));
    $ok('rincian BPJS 12jt', $rp($cari($detail['rows'], 'detail', '50301010102')['amount'] ?? 0) === 12_000_000);
    $ok('baris Ledger BEBAN KARYAWAN 42jt', $rp($cari($detail['rows'], 'line', '50301')['amount'] ?? 0) === 42_000_000,
        (string) $rp($cari($detail['rows'], 'line', '50301')['amount'] ?? 0));
    /* Inti nomor 4: akun pendapatan bertanda kredit. Bila tanda induk tidak ikut diterapkan ke
       rinciannya, induk tampil +200jt sementara rinciannya −200jt di baris tepat di atasnya. */
    $ok('rincian pendapatan POSITIF seperti induknya, bukan negatif',
        $rp($cari($detail['rows'], 'detail', '40101010101')['amount'] ?? 0) === 200_000_000,
        (string) $rp($cari($detail['rows'], 'detail', '40101010101')['amount'] ?? 0));

    echo "\n=== 5. NODE YANG ANAKNYA SALING MENIADAKAN ===\n";

    /* +5jt dan −5jt di bawah node 50306-an: nettonya nol. Di mode ringkas node itu memang
       dibuang; di mode detail ia WAJIB tampil, karena justru keadaan inilah yang dicari orang
       ketika membuka rincian. */
    $pasangan = [(string) $plus->code, (string) $minus->code];
    $adaDiDetail = collect($detail['rows'])->filter(fn ($r) => $r['kind'] === 'detail' && in_array((string) $r['code'], $pasangan, true))->count();
    $ok('kedua akun yang saling meniadakan tampil di mode detail', $adaDiDetail === 2, "$adaDiDetail dari 2");
    $indukNol = $cari($detail['rows'], 'line', substr((string) $plus->code, 0, 5));
    $ok('baris Ledger-nya ikut tampil walau nettonya nol', $indukNol !== null && $rp($indukNol['amount']) === 0,
        $indukNol === null ? 'baris tidak ada' : (string) $rp($indukNol['amount']));
    $ok('di mode ringkas node bernetto nol itu TIDAK ditampilkan',
        $cari($ringkas['rows'], 'line', substr((string) $plus->code, 0, 5)) === null, 'ikut tampil di mode ringkas');

    echo "\n=== 6. BENTUK BARIS ===\n";

    $kindAsing = array_values(array_unique(array_diff(array_column($detail['rows'], 'kind'), $KIND_DIKENAL)));
    /* Rantai v-if di layar tidak punya penjaga tipe: jenis baris yang belum punya cabang akan
       hilang dari laporan tanpa error apa pun. Daftar ini yang menjaganya. */
    $ok('tidak ada jenis baris di luar daftar yang dikenal layar', $kindAsing === [], implode(', ', $kindAsing));
    $tanpaCode = collect($detail['rows'])->reject(fn ($r) => array_key_exists('code', $r))->count();
    $ok('SETIAP baris punya kunci code (termasuk header & total)', $tanpaCode === 0, "$tanpaCode baris tanpa kunci code");
    $ok('baris judul & total tidak berkode',
        collect($detail['rows'])->filter(fn ($r) => in_array($r['kind'], ['header', 'total'], true) && $r['code'] !== null)->count() === 0);
    $ok('jumlah akun yang tampil dilaporkan apa adanya',
        (int) $detail['detail']['tampil'] === count($hanya($detail['rows'], ['detail'])),
        $detail['detail']['tampil'].' vs '.count($hanya($detail['rows'], ['detail'])));
    $ok('penyebut = jumlah akun postable pendapatan & beban',
        (int) $detail['detail']['total'] === (int) DB::table('bs_accounts')->where('tenant_id', $tenant)
            ->where('is_postable', 1)->whereIn('type', ['income', 'expense'])->count(),
        (string) $detail['detail']['total']);

    echo "\n=== 7. NILAI view YANG ASING JATUH KE RINGKAS ===\n";

    foreach (['', 'DETAIL', 'ya', '1', 'ringkas'] as $aneh) {
        $p = $props(['date_from' => $DARI, 'date_to' => $SAMPAI, 'view' => $aneh]);
        $ok("view='$aneh' diperlakukan sebagai ringkas", ($p['view'] ?? '') === 'summary' && $hanya($p['rows'], ['detail']) === [],
            (string) ($p['view'] ?? '?'));
    }

    echo "\n=== 8. EXPORT EXCEL ===\n";

    $xR = $isiXlsx($xlsx(['date_from' => $DARI, 'date_to' => $SAMPAI]));
    $xD = $isiXlsx($xlsx(['date_from' => $DARI, 'date_to' => $SAMPAI, 'view' => 'detail']));
    $ok('berkas mode ringkas terbaca & memuat judulnya', str_contains($xR, 'LAPORAN LABA RUGI') && str_contains($xR, 'LABA RUGI NETTO'));
    $ok('berkas mode detail ditandai (DETAIL) pada judulnya', str_contains($xD, 'LAPORAN LABA RUGI (DETAIL)'), 'judul tidak ditandai');
    $ok('berkas mode detail memuat kode akun 11 digit', str_contains($xD, '50301010101'), 'kode akun tidak ada');
    /* Inti nomor 7: berkas yang selama ini diterima orang tidak boleh ikut berubah. */
    $ok('berkas mode ringkas TIDAK memuat kode akun', ! str_contains($xR, '50301010101'), 'kode akun bocor ke berkas ringkas');
    /* Diperiksa pada kode LEDGER-nya juga, bukan hanya kode 11 digit: yang paling mudah bocor
       justru kode 5 digit pada baris Ledger, dan baris itu ADA di kedua mode. Bila kode ikut
       tercetak di sana, berkas mode ringkas yang selama ini dipakai orang berubah bentuk tanpa
       ada yang meminta. '50301  ' (dua spasi pemisah) tidak mungkin cocok dengan kode 11 digit. */
    $ok('kode Ledger tidak dicetak di berkas mana pun — hanya rincian yang berkode',
        ! str_contains($xR, '50301  ') && ! str_contains($xD, '50301  '),
        'kode Ledger ikut tercetak: ringkas='.(str_contains($xR, '50301  ') ? 'ya' : 'tidak').' detail='.(str_contains($xD, '50301  ') ? 'ya' : 'tidak'));
    $ok('berkas mode ringkas tidak ditandai (DETAIL)', ! str_contains($xR, '(DETAIL)'));
    $ok('rincian di Excel menjorok lebih dalam daripada baris Ledger-nya',
        str_contains($xD, str_repeat(' ', 12).'50301010101'), 'jorokan rincian tidak lebih dalam');
} finally {
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    DB::rollBack();
}

echo "\n────────────────────────────────\n";
echo "LULUS: $lulus   GAGAL: $gagal\n";
echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
