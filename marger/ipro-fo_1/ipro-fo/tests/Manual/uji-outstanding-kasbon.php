<?php

/**
 * OUTSTANDING KASBON per karyawan — Cash Book → Master → Outstanding Kasbon.
 *
 *   Saldo Awal Kasbon   = mutasi Uang Muka Operasional karyawan itu SEBELUM tanggal periode awal
 *   Penambahan Kasbon   = DEBIT Uang Muka Operasional dalam periode
 *   Realisasi Kasbon    = KREDIT Uang Muka Operasional dalam periode
 *   Outstanding Kasbon  = Saldo Awal + Penambahan − Realisasi
 *
 * YANG PALING MUDAH RUSAK DIAM-DIAM:
 *   1. PEMILIK BARIS. Uang Muka Operasional bergerak lewat empat pintu jurnal yang berbeda:
 *      pembayaran bon reguler, ALOKASI KASBON (bon Debit VA), realisasi, dan refund/reimburse.
 *      Jurnal alokasi TIDAK menempel pada bonnya melainkan pada baris alokasinya sendiri —
 *      melewatkannya membuat seluruh kasbon Debit VA jatuh ke "(tanpa pemegang)" tanpa galat.
 *   2. BATAS PERIODE. Saldo awal harus akumulasi mutasi SEBELUM tanggal awal; kalau batas itu
 *      hilang, saldo awal selalu nol dan outstanding-nya benar hanya pada periode pertama.
 *   3. SISI DEBIT/KREDIT. Tertukar sedikit pun, "penambahan" dan "realisasi" bertukar tempat
 *      dan outstanding-nya berubah tanda — tetap terlihat seperti angka yang wajar.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-outstanding-kasbon.php';"
 */

use App\Domain\BusinessSupport\Services\CashAdvanceAllocationService;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Http\Controllers\Web\BusinessSupport\CashBook\OutstandingKasbonController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => $u->canModule('cashbook')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

$svc = app(CashAdvanceService::class);
$alokSvc = app(CashAdvanceAllocationService::class);

$reqInertia = function (array $query = []) use ($user) {
    $r = Request::create('/uji', 'GET', $query, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);

    return $r;
};
$layar = fn (array $q) => app(OutstandingKasbonController::class)
    ->index($reqInertia($q))->toResponse($reqInertia())->getData(true)['props'];

$akunUmOp = DB::table('bs_accounts')->where('code', config('business_support.gl_accounts.advance_op'))->value('id');
if (! $akunUmOp) { echo "Akun Uang Muka Operasional tidak ada — uji dihentikan.\n"; return; }

$bank = DB::table('bs_accounts')->where('is_postable', 1)->where('code', 'like', '101010%')
    ->whereNotIn('id', [$akunUmOp])->orderBy('code')->limit(2)->pluck('id')->all();
if (count($bank) < 2) { echo "Rekening bank tidak cukup — uji dihentikan.\n"; return; }
[$BANK_A, $BANK_B] = $bank;

$cc = DB::table('bs_cost_centers')->where('is_active', 1)->value('id');
$akunBeban = DB::table('bs_accounts')->where('is_postable', 1)->where('type', 'expense')->orderBy('code')->value('id');
if (! $cc || ! $akunBeban) { echo "Cost center / akun beban tidak ada — uji dihentikan.\n"; return; }

$TAHUN = 2033;
$BULAN = 4;
$TGL   = sprintf('%04d-%02d-10', $TAHUN, $BULAN);
$AWAL  = sprintf('%04d-%02d-01', $TAHUN, $BULAN);
$AKHIR = sprintf('%04d-%02d-28', $TAHUN, $BULAN);

DB::beginTransaction();

/* Langkah baru: Alokasi Bon Sementara → tombol "Lanjut Realisasikan Bon". Bon Debit VA model
   baru tidak bisa direalisasi sebelum ini (CashAdvanceService::realize, ADV_ALLOCATION_NOT_CONFIRMED). */
$lanjutkanAlokasi = function ($bon) {
    if (app(\App\Domain\BusinessSupport\Services\CashAdvanceService::class)->perluLanjutAlokasi($bon)) {
        $bon->update(['allocation_done_at' => now()]);
        $bon->refresh();
    }

    return $bon;
};


try {
    app(JournalService::class)->ensurePeriod($TAHUN, $BULAN);
    app(JournalService::class)->ensurePeriod($TAHUN, $BULAN + 1);

    $kartu = \App\Domain\BusinessSupport\Models\BsCreditCard::create([
        'account_id' => $BANK_B, 'card_number' => 'UJI-OK-001', 'name' => 'KARTU UJI OUTSTANDING',
        'is_active' => true, 'balance' => 0, 'expired_date' => sprintf('%04d-12-31', $TAHUN),
        'created_by' => $user->id,
    ]);

    $buatBon = fn (string $holder, float $amount) => $svc->issue([
        'advance_date'   => $AWAL,
        'holder'         => $holder,
        'purpose'        => 'Uji outstanding kasbon',
        'amount'         => $amount,
        'cost_center_id' => $cc,
        'approval_mode'  => 'offline',
    ], $user->id);

    // A — bon REGULER: pembayarannya sendiri yang mendebit Uang Muka Operasional.
    $bonA = $buatBon('UJI OK Reguler', 500000);
    $svc->pay($bonA, ['paid_date' => $TGL, 'payment_method' => 'reguler', 'cash_account_id' => $BANK_A], $user->id);

    // B — bon DEBIT VA yang sudah beres: alokasi 800rb, realisasi 600rb, refund 200rb → nol.
    $bonB = $buatBon('UJI OK Debit VA', 2000000);
    $svc->pay($bonB, ['paid_date' => $TGL, 'payment_method' => 'debit_va',
        'va_expired_date' => sprintf('%04d-12-31', $TAHUN), 'credit_card_id' => $kartu->id], $user->id);
    $bonB->refresh();
    $alokSvc->tambah($bonB, ['alloc_date' => $TGL, 'description' => 'Beli ATK', 'amount' => 500000, 'cash_account_id' => $BANK_A], $user->id);
    $alokSvc->tambah($bonB, ['alloc_date' => $TGL, 'description' => 'Transport', 'amount' => 300000, 'cash_account_id' => $BANK_B], $user->id);
    $bonB->refresh();
    $lanjutkanAlokasi($bonB);
    $svc->realize($bonB, ['realized_date' => $TGL, 'defer_settlement' => true,
        'lines' => [['expense_account_id' => $akunBeban, 'cost_center_id' => $cc, 'description' => 'Belanja', 'amount' => 600000]]], $user->id);
    $bonB->refresh();
    $svc->refund($bonB, $TGL, $user->id);

    // C — bon DEBIT VA yang BELUM diselesaikan: alokasi 400rb, realisasi 100rb → outstanding 300rb.
    $bonC = $buatBon('UJI OK Menggantung', 400000);
    $svc->pay($bonC, ['paid_date' => $TGL, 'payment_method' => 'debit_va',
        'va_expired_date' => sprintf('%04d-12-31', $TAHUN), 'credit_card_id' => $kartu->id], $user->id);
    $bonC->refresh();
    $alokSvc->tambah($bonC, ['alloc_date' => $TGL, 'description' => 'Operasional', 'amount' => 400000, 'cash_account_id' => $BANK_A], $user->id);
    $bonC->refresh();
    $lanjutkanAlokasi($bonC);
    $svc->realize($bonC, ['realized_date' => $TGL, 'defer_settlement' => true,
        'lines' => [['expense_account_id' => $akunBeban, 'cost_center_id' => $cc, 'description' => 'Belanja', 'amount' => 100000]]], $user->id);

    /* D — mutasi Uang Muka Operasional yang TIDAK berasal dari bon mana pun (jurnal memorial).
       Ia tidak boleh dibuang: baris yang hilang diam-diam membuat jumlah layar tidak lagi sama
       dengan saldo akunnya, dan tidak ada yang memberi tahu selisihnya. */
    app(JournalService::class)->post([
        'journal_date' => $TGL, 'source' => 'memorial', 'status' => 'posted',
        'reference' => 'UJI-MEMO-OK', 'description' => 'Panjar tanpa bon', 'created_by' => $user->id,
    ], [
        ['account_id' => $akunUmOp, 'cost_center_id' => $cc, 'debit' => 150000, 'credit' => 0, 'description' => 'Memorial'],
        ['account_id' => $BANK_A, 'cost_center_id' => $cc, 'debit' => 0, 'credit' => 150000, 'description' => 'Memorial'],
    ]);

    /* E — jurnal yang BELUM disetujui (status final). Outstanding dibaca dari jurnal APPROVED
       saja, sepola Rekening Ledger: angka yang ikut bergerak oleh jurnal yang masih diantre
       tidak bisa dicocokkan dengan buku besar mana pun. */
    app(JournalService::class)->post([
        'journal_date' => $TGL, 'source' => 'memorial', 'status' => 'final',
        'reference' => 'UJI-BELUM-APPROVE', 'description' => 'Panjar belum disetujui', 'created_by' => $user->id,
    ], [
        ['account_id' => $akunUmOp, 'cost_center_id' => $cc, 'debit' => 999000, 'credit' => 0, 'description' => 'Belum approve'],
        ['account_id' => $BANK_A, 'cost_center_id' => $cc, 'debit' => 0, 'credit' => 999000, 'description' => 'Belum approve'],
    ]);

    echo "\n=== 1. SUMMARY — SELURUH KARYAWAN ===\n";

    $p = $layar(['from' => $AWAL, 'to' => $AKHIR, 'mode' => 'summary']);
    $rows = collect($p['rows']);

    $a = $rows->firstWhere('holder', 'UJI OK Reguler');
    $b = $rows->firstWhere('holder', 'UJI OK Debit VA');
    $c = $rows->firstWhere('holder', 'UJI OK Menggantung');

    $ok('bon reguler muncul (jurnal pembayarannya mendebit Uang Muka Op)', $a !== null,
        $rows->pluck('holder')->join(', '));
    $ok('bon Debit VA muncul lewat jurnal ALOKASI-nya', $b !== null,
        'jurnal alokasi tidak tertelusuri ke pemegangnya');
    /* Mutasi yang tidak berasal dari bon mana pun TETAP tampil, dikumpulkan sebagai
       "(tanpa pemegang)" — dan besarnya PERSIS jurnal memorial itu saja. Kalau ada mutasi bon
       yang ikut jatuh ke sini, angkanya akan lebih besar dari 150.000. */
    $tanpa = $rows->firstWhere('holder', '(tanpa pemegang)');
    $ok('mutasi tanpa bon tetap tampil, tidak dibuang diam-diam', $tanpa !== null,
        'baris tak bertuan hilang — jumlah layar tidak lagi sama dengan saldo akunnya');
    $ok('hanya jurnal memorial itu yang tak bertuan (mutasi bon semuanya bertuan)',
        $tanpa && abs((float) $tanpa['penambahan'] - 150000) < 0.01,
        $tanpa ? (string) $tanpa['penambahan'].' (seharusnya 150.000)' : '');

    // Jurnal yang belum disetujui tidak boleh ikut menggerakkan angka outstanding.
    $ok('jurnal belum approved TIDAK ikut terhitung',
        $rows->every(fn (array $r) => abs((float) $r['penambahan'] - 999000) > 0.01)
        && abs((float) $p['totals']['penambahan'] - 999000) > 0.01,
        'total penambahan '.$p['totals']['penambahan']);

    if ($a) {
        $ok('Penambahan bon reguler = nominal bonnya', abs((float) $a['penambahan'] - 500000) < 0.01, (string) $a['penambahan']);
        $ok('Realisasi bon reguler masih nol', abs((float) $a['realisasi']) < 0.01, (string) $a['realisasi']);
        $ok('Outstanding bon reguler = 500.000', abs((float) $a['outstanding'] - 500000) < 0.01, (string) $a['outstanding']);
    }
    if ($b) {
        $ok('Penambahan bon Debit VA = TOTAL ALOKASI, bukan nominal bon',
            abs((float) $b['penambahan'] - 800000) < 0.01, (string) $b['penambahan'].' (nominal bonnya 2.000.000)');
        $ok('Realisasi = realisasi + refund', abs((float) $b['realisasi'] - 800000) < 0.01, (string) $b['realisasi']);
        $ok('bon yang sudah beres berakhir outstanding nol', abs((float) $b['outstanding']) < 0.01, (string) $b['outstanding']);
    }
    if ($c) {
        $ok('bon yang belum diselesaikan menyisakan outstanding',
            abs((float) $c['outstanding'] - 300000) < 0.01, (string) $c['outstanding']);
        $ok('Outstanding = Saldo Awal + Penambahan − Realisasi',
            abs((float) $c['outstanding'] - ((float) $c['saldo_awal'] + (float) $c['penambahan'] - (float) $c['realisasi'])) < 0.01);
    }

    $ok('total layar = jumlah barisnya',
        abs((float) $p['totals']['outstanding'] - (float) $rows->sum('outstanding')) < 0.01);
    $ok('jumlah karyawan dihitung', (int) $p['totals']['karyawan'] === $rows->count());
    $ok('daftar karyawan dikirim untuk pilihan Detail',
        in_array('UJI OK Menggantung', $p['holders'], true));

    echo "\n=== 2. SALDO AWAL MENGIKUTI TANGGAL PERIODE AWAL ===\n";

    $sesudah = $layar(['from' => sprintf('%04d-%02d-01', $TAHUN, $BULAN + 1), 'to' => sprintf('%04d-%02d-28', $TAHUN, $BULAN + 1)]);
    $cSesudah = collect($sesudah['rows'])->firstWhere('holder', 'UJI OK Menggantung');
    $ok('saldo awal periode berikutnya = outstanding periode ini',
        $cSesudah && abs((float) $cSesudah['saldo_awal'] - 300000) < 0.01,
        $cSesudah ? (string) $cSesudah['saldo_awal'] : 'baris hilang');
    $ok('penambahan periode berikutnya nol', $cSesudah && abs((float) $cSesudah['penambahan']) < 0.01);
    $ok('outstanding periode berikutnya tetap 300.000',
        $cSesudah && abs((float) $cSesudah['outstanding'] - 300000) < 0.01);

    // Periode yang MENDAHULUI seluruh mutasi: saldo awalnya nol, bukan ikut terhitung.
    $sebelum = $layar(['from' => sprintf('%04d-%02d-01', $TAHUN, $BULAN - 1), 'to' => sprintf('%04d-%02d-28', $TAHUN, $BULAN - 1)]);
    $cSebelum = collect($sebelum['rows'])->firstWhere('holder', 'UJI OK Menggantung');
    $ok('sebelum mutasinya terjadi, karyawan itu belum punya angka apa pun', $cSebelum === null,
        $cSebelum ? json_encode($cSebelum) : '');

    echo "\n=== 3. DETAIL — SATU KARYAWAN ===\n";

    $d = $layar(['from' => $AWAL, 'to' => $AKHIR, 'mode' => 'detail', 'holder' => 'UJI OK Debit VA']);
    $baris = collect($d['detail']);

    $ok('detail memuat mutasi karyawan itu', $baris->count() > 0);
    /* Diperiksa lewat REFERENSI jurnalnya, bukan lewat kata-kata di keterangannya: seluruh jurnal
       sebuah bon (alokasi, realisasi, penyelesaian) memakai nomor bon itu sebagai reference,
       sedangkan keterangannya berbeda-beda dan bisa berubah kalimatnya sewaktu-waktu. */
    $ok('detail HANYA memuat mutasi bon karyawan yang dipilih',
        $baris->every(fn ($x) => $x['reference'] === $bonB->advance_number),
        $baris->pluck('reference')->unique()->join(' | '));
    $ok('alokasi terbaca sebagai penambahan (debit)',
        abs((float) $baris->sum('debit') - 800000) < 0.01, (string) $baris->sum('debit'));
    $ok('realisasi & refund terbaca sebagai realisasi (kredit)',
        abs((float) $baris->sum('credit') - 800000) < 0.01, (string) $baris->sum('credit'));
    $ok('tiap baris membawa saldo berjalan', $baris->every(fn ($x) => array_key_exists('saldo', $x)));
    $ok('saldo berjalan terakhir = outstanding karyawan itu',
        abs((float) $baris->last()['saldo'] - (float) $b['outstanding']) < 0.01,
        $baris->last()['saldo'].' vs '.$b['outstanding']);
    $ok('baris detail bertaut ke jurnalnya', $baris->every(fn ($x) => (int) $x['journal_id'] > 0));
    $ok('urutan detail menurut tanggal', $baris->pluck('date')->values()->all() === $baris->pluck('date')->sort()->values()->all());

    // Karyawan yang tidak dipilih tidak boleh ikut muncul.
    $dLain = $layar(['from' => $AWAL, 'to' => $AKHIR, 'mode' => 'detail', 'holder' => 'UJI OK Reguler']);
    $ok('mengganti karyawan mengganti isi detailnya',
        abs((float) collect($dLain['detail'])->sum('debit') - 500000) < 0.01,
        (string) collect($dLain['detail'])->sum('debit'));
    $ok('mode summary tidak ikut mengirim rincian', $p['detail'] === []);

    echo "\n=== 4. SARINGAN YANG SALAH TIDAK MENYESATKAN ===\n";

    $balik = $layar(['from' => $AKHIR, 'to' => $AWAL]);
    $ok('rentang tanggal terbalik ditukar sendiri, bukan menghasilkan daftar kosong',
        $balik['filters']['from'] === $AWAL && $balik['filters']['to'] === $AKHIR,
        $balik['filters']['from'].' → '.$balik['filters']['to']);
    $ok('daftarnya tetap terisi sesudah ditukar', count($balik['rows']) === $rows->count());

    $kosong = $layar(['from' => $AWAL, 'to' => $AKHIR, 'mode' => 'detail', 'holder' => 'KARYAWAN YANG TIDAK ADA']);
    $ok('karyawan yang tidak ada menghasilkan rincian kosong, bukan seluruh baris', $kosong['detail'] === []);

    $ok('akun sumbernya diberitahukan ke layar',
        ($p['account']['code'] ?? null) === config('business_support.gl_accounts.advance_op'),
        json_encode($p['account'] ?? null));
} finally {
    DB::rollBack();
}

echo "\n────────────────────────────────\n";
echo "LULUS: $lulus   GAGAL: $gagal\n";
echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
