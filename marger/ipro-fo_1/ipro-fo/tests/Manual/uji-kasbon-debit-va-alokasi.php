<?php

/**
 * Bon Sementara Debit VA — pembayaran TANPA jurnal, pengeluaran lewat ALOKASI KASBON, dan
 * saldo kartu per bulan (Saldo Awal · Penambahan · Pemakaian · Sisa Saldo) + closing.
 *
 * Layar Outstanding Kasbon yang membaca mutasi hasil alokasi ini diuji tersendiri di
 * tests/Manual/uji-outstanding-kasbon.php.
 *
 * APA YANG BERUBAH DAN KENAPA PERLU DIJAGA.
 *   1. Pembayaran Debit VA dulu membentuk jurnal Dr akun kartu / Cr bank pada TANGGAL TRANSFER.
 *      Itu mencatat bank berkurang padahal uangnya belum ke mana-mana — ia hanya disediakan pada
 *      VA-nya. Sekarang pembayaran Debit VA TIDAK berjurnal sama sekali dan tidak lagi meminta
 *      sumber bank/kas.
 *   2. Yang mengeluarkan uang dari bank adalah ALOKASI KASBON: Dr Uang Muka Operasional / Cr bank
 *      pengeluaran, bisa beberapa baris pada tanggal & bank berbeda.
 *   3. Karena itu Uang Muka Operasional bon Debit VA = TOTAL ALOKASI, bukan nominal bonnya.
 *      Realisasi/refund/reimburse berhitung terhadap angka itu. Memakai nominal bon akan
 *      menjanjikan refund atas uang yang belum pernah keluar dari bank — tanpa galat apa pun.
 *   4. Bon Debit VA LAMA (yang sudah terlanjur berjurnal) harus tetap berperilaku lama. Pembeda-
 *      nya DATA (journal_id), bukan payment_method.
 *   5. Kolom "Pemakaian" kartu dulu dibaca dari SUM(credit) akun kartunya di buku besar. Karena
 *      akun kartu tidak lagi bermutasi, kolom itu akan menampilkan NOL tanpa galat — jadi ia
 *      harus dibaca dari Alokasi Kasbon.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-kasbon-debit-va-alokasi.php';"
 */

use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCashAdvanceAllocation;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Models\BsCreditCardClosing;
use App\Domain\BusinessSupport\Models\BsCreditCardRequest;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\CashAdvanceAllocationService;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Domain\BusinessSupport\Services\CreditCardClosingService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CreditCardController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
/** Jalankan sesuatu yang SEHARUSNYA ditolak; kembalikan errorCode-nya (atau null bila lolos). */
$tolak = function (callable $fn): ?string {
    try { $fn(); } catch (BusinessRuleException $e) { return $e->errorCode(); }

    return null;
};

$user = User::all()->first(fn ($u) => $u->canModule('cashbook')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

$svc = app(CashAdvanceService::class);
$alokSvc = app(CashAdvanceAllocationService::class);
$closingSvc = app(CreditCardClosingService::class);

$reqInertia = function (array $query = []) use ($user) {
    $r = Request::create('/uji', 'GET', $query, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);

    return $r;
};

$akunUmOp = DB::table('bs_accounts')->where('code', config('business_support.gl_accounts.advance_op'))->value('id');
if (! $akunUmOp) { echo "Akun Uang Muka Operasional tidak ada — uji dihentikan.\n"; return; }

$bank = DB::table('bs_accounts')->where('is_postable', 1)->where('code', 'like', '101010%')
    ->whereNotIn('id', [$akunUmOp])->orderBy('code')->limit(2)->pluck('id')->all();
if (count($bank) < 2) { echo "Rekening bank tidak cukup — uji dihentikan.\n"; return; }
[$BANK_A, $BANK_B] = $bank;

$cc = DB::table('bs_cost_centers')->where('is_active', 1)->value('id');
if (! $cc) { echo "Cost center tidak ada — uji dihentikan.\n"; return; }

$TAHUN = 2032;   // masa khayalan, tidak bertabrakan dengan data nyata
$BULAN = 5;
$TGL   = sprintf('%04d-%02d-10', $TAHUN, $BULAN);

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
    /* Periode fiskal bulan uji dibuka dulu — JournalService menolak posting pada periode closed,
       dan tanpa ini seluruh uji gagal karena sebab yang tidak ada hubungannya. */
    app(\App\Domain\BusinessSupport\Services\JournalService::class)->ensurePeriod($TAHUN, $BULAN);
    app(\App\Domain\BusinessSupport\Services\JournalService::class)->ensurePeriod($TAHUN, $BULAN + 1);

    $kartu = BsCreditCard::create([
        'account_id' => $BANK_B, 'card_number' => 'UJI-VA-001', 'name' => 'KARTU UJI ALOKASI',
        'is_active' => true, 'balance' => 0, 'expired_date' => sprintf('%04d-12-31', $TAHUN),
        'created_by' => $user->id,
    ]);

    /** Bon baru yang langsung lolos gerbang persetujuan (offline → requiredSigners kosong). */
    $buatBon = function (string $holder, float $amount) use ($svc, $user, $cc, $TAHUN, $BULAN) {
        return $svc->issue([
            'advance_date'   => sprintf('%04d-%02d-01', $TAHUN, $BULAN),
            'holder'         => $holder,
            'purpose'        => 'Uji alokasi kasbon',
            'amount'         => $amount,
            'cost_center_id' => $cc,
            'approval_mode'  => 'offline',
        ], $user->id);
    };

    echo "\n=== 1. PEMBAYARAN DEBIT VA TIDAK MEMBENTUK JURNAL ===\n";

    $bon = $buatBon('UJI Eko Prasetyo', 2000000);
    $jurnalSebelum = BsJournal::count();

    $svc->pay($bon, [
        'paid_date'       => $TGL,
        'payment_method'  => 'debit_va',
        'va_expired_date' => sprintf('%04d-12-31', $TAHUN),
        'credit_card_id'  => $kartu->id,
        // Sengaja TIDAK mengirim cash_account_id — memang tidak diperlukan lagi.
    ], $user->id);
    $bon->refresh();

    $ok('tidak ada jurnal baru dari pembayaran Debit VA', BsJournal::count() === $jurnalSebelum,
        (BsJournal::count() - $jurnalSebelum).' jurnal baru');
    $ok('journal_id bon tetap kosong', $bon->journal_id === null, (string) $bon->journal_id);
    $ok('sumber bank/kas tidak ikut tersimpan', $bon->cash_account_id === null, (string) $bon->cash_account_id);
    $ok('status bon menjadi outstanding', $bon->status === 'outstanding', (string) $bon->status);
    $ok('kartunya tercatat pada bon', (int) $bon->credit_card_id === (int) $kartu->id);

    // Permintaan otomatis: masa berlaku SAJA. Penambahan saldo kini dibaca dari pembayarannya
    // sendiri; mengajukannya lagi akan menghitung uang yang sama dua kali.
    $minta = BsCreditCardRequest::where('credit_card_id', $kartu->id)->where('source_id', $bon->id)->get();
    $ok('permintaan masa berlaku tetap dibuat', $minta->where('type', 'expiry')->count() === 1);
    $ok('permintaan penambahan saldo TIDAK dibuat lagi', $minta->where('type', 'topup')->count() === 0,
        'akan menghitung nominal bon dua kali');

    /* Layar TETAP mengirimkan cash_account_id: payForm menyimpannya walau kolomnya disembunyikan,
       jadi operator yang sempat memilih bank lalu berpindah ke Debit VA akan mengirim nilai itu.
       Nilainya harus DIBUANG, bukan sekadar "tidak diisi" — bank sumber yang tersimpan pada bon
       tanpa jurnal akan terbaca sebagai bank yang uangnya sudah keluar. */
    $bonSisipan = $buatBon('UJI Bank Nyasar', 250000);
    $svc->pay($bonSisipan, [
        'paid_date'       => $TGL,
        'payment_method'  => 'debit_va',
        'va_expired_date' => sprintf('%04d-12-31', $TAHUN),
        'credit_card_id'  => $kartu->id,
        'cash_account_id' => $BANK_A,   // ← sisa isian layar yang tidak boleh ikut tersimpan
    ], $user->id);
    $bonSisipan->refresh();
    $ok('cash_account_id yang terlanjur terkirim DIBUANG pada Debit VA',
        $bonSisipan->cash_account_id === null, (string) $bonSisipan->cash_account_id);
    $ok('bon itu pun tetap tanpa jurnal', $bonSisipan->journal_id === null);

    // Pembayaran REGULER tetap wajib bersumber bank/kas, dan tetap berjurnal.
    $bonReg = $buatBon('UJI Budi Santoso', 500000);
    $kode = $tolak(fn () => $svc->pay($bonReg, ['paid_date' => $TGL, 'payment_method' => 'reguler'], $user->id));
    $ok('pembayaran reguler tanpa bank ditolak', $kode === 'ADV_NO_CASH', (string) $kode);
    $svc->pay($bonReg, ['paid_date' => $TGL, 'payment_method' => 'reguler', 'cash_account_id' => $BANK_A], $user->id);
    $bonReg->refresh();
    $ok('pembayaran reguler tetap berjurnal', $bonReg->journal_id !== null);

    echo "\n=== 2. ALOKASI KASBON — Dr Uang Muka Operasional / Cr bank pengeluaran ===\n";

    $a1 = $alokSvc->tambah($bon, ['alloc_date' => $TGL, 'description' => 'Beli ATK', 'amount' => 500000, 'cash_account_id' => $BANK_A], $user->id);
    $a2 = $alokSvc->tambah($bon, ['alloc_date' => $TGL, 'description' => 'Transport', 'amount' => 300000, 'cash_account_id' => $BANK_B], $user->id);

    $j1 = BsJournal::with('lines')->find($a1->journal_id);
    $barisDebit = $j1->lines->firstWhere('debit', '>', 0);
    $barisKredit = $j1->lines->firstWhere('credit', '>', 0);
    /* Kedua alokasi ini sengaja BERBEDA BANK, jadi jurnalnya memang terpisah. Aturan
       penggabungan (tanggal & bank SAMA → satu jurnal) diuji tersendiri di
       tests/Manual/uji-alokasi-kasbon-satu-jurnal.php. */
    $ok('alokasi membentuk jurnal', $j1 !== null);
    $ok('bank berbeda → jurnalnya terpisah', $a1->journal_id !== $a2->journal_id,
        json_encode([$a1->journal_id, $a2->journal_id]));
    $ok('sisi DEBIT = Uang Muka Operasional', (int) $barisDebit->account_id === (int) $akunUmOp,
        (string) $barisDebit->account_id);
    $ok('sisi KREDIT = bank pengeluaran yang dipilih', (int) $barisKredit->account_id === (int) $BANK_A,
        (string) $barisKredit->account_id);
    $ok('nominal jurnalnya = nominal alokasinya', abs((float) $barisDebit->debit - 500000) < 0.01);
    $ok('cost center dibawa KEDUA baris (dibaca Budget Control)',
        (int) $barisDebit->cost_center_id === (int) $cc && (int) $barisKredit->cost_center_id === (int) $cc);
    $ok('bank alokasi kedua boleh berbeda',
        (int) BsJournal::with('lines')->find($a2->journal_id)->lines->firstWhere('credit', '>', 0)->account_id === (int) $BANK_B);

    // Alokasi melebihi sisa bon ditolak — alokasi membagi bon, bukan menambah pengeluaran baru.
    $kode = $tolak(fn () => $alokSvc->tambah($bon, ['alloc_date' => $TGL, 'description' => 'Kelebihan', 'amount' => 5000000, 'cash_account_id' => $BANK_A], $user->id));
    $ok('alokasi melebihi sisa bon ditolak', $kode === 'ALLOC_EXCEEDS', (string) $kode);

    // Bon reguler tidak punya alokasi — uangnya sudah keluar saat dibayar.
    $kode = $tolak(fn () => $alokSvc->tambah($bonReg, ['alloc_date' => $TGL, 'description' => 'X', 'amount' => 1000, 'cash_account_id' => $BANK_A], $user->id));
    $ok('bon reguler tidak bisa dialokasikan', $kode === 'ALLOC_NOT_DEBIT_VA', (string) $kode);

    echo "\n=== 3. ACUAN UANG MUKA OPERASIONAL = TOTAL ALOKASI, BUKAN NOMINAL BON ===\n";

    $bon->refresh();
    $totalAlokasi = $alokSvc->totalAlokasi($bon);
    $ok('total alokasi = 800.000', abs($totalAlokasi - 800000) < 0.01, (string) $totalAlokasi);

    $akunBeban = DB::table('bs_accounts')->where('is_postable', 1)->where('type', 'expense')->orderBy('code')->value('id');
    $lanjutkanAlokasi($bon);
    $svc->realize($bon, [
        'realized_date'    => $TGL,
        'defer_settlement' => true,
        'lines'            => [['expense_account_id' => $akunBeban, 'cost_center_id' => $cc, 'description' => 'Belanja', 'amount' => 600000]],
    ], $user->id);
    $bon->refresh();

    $ok('settlement dihitung terhadap ALOKASI (800rb − 600rb)', abs((float) $bon->settlement - 200000) < 0.01,
        (string) $bon->settlement.' (kalau 1.400.000 berarti masih memakai nominal bon)');

    $jr = BsJournal::with('lines')->find($bon->realize_journal_id);
    $lawan = $jr->lines->firstWhere('credit', '>', 0);
    $ok('realisasi mengkredit Uang Muka Operasional, bukan akun kartu',
        (int) $lawan->account_id === (int) $akunUmOp, (string) $lawan->account_id);

    // Alokasi terkunci sesudah realisasi.
    $kode = $tolak(fn () => $alokSvc->hapus($a2, $user->id));
    $ok('alokasi tidak bisa dihapus sesudah realisasi', $kode === 'ALLOC_LOCKED', (string) $kode);

    echo "\n=== 4. REFUND MEMAKAI BANK ALOKASI TERAKHIR SEBAGAI CADANGAN ===\n";

    // Bon Debit VA tidak punya cash_account_id sama sekali; tanpa cadangan ini, setiap refund
    // bon VA akan ditolak dengan ADV_NO_CASH.
    $svc->refund($bon, $TGL, $user->id);
    $bon->refresh();
    $js = BsJournal::with('lines')->find($bon->settle_journal_id);
    $ok('refund berhasil tanpa memilih bank', $bon->settlement_status === 'settled', (string) $bon->settlement_status);
    $ok('bank penerima refund = bank alokasi terakhir',
        (int) $js->lines->firstWhere('debit', '>', 0)->account_id === (int) $BANK_B,
        (string) $js->lines->firstWhere('debit', '>', 0)->account_id);
    $ok('refund mengkredit Uang Muka Operasional',
        (int) $js->lines->firstWhere('credit', '>', 0)->account_id === (int) $akunUmOp);

    echo "\n=== 5. BON DEBIT VA MODEL LAMA TETAP BERPERILAKU LAMA ===\n";

    /* Bon lama = punya journal_id (pembayarannya dulu berjurnal ke akun kartu). Bila pembedanya
       hanya payment_method, bon lama akan mengkredit Uang Muka Operasional yang tidak pernah
       menerima panjarnya — akun kartunya bersaldo debit selamanya. */
    $bonLama = $buatBon('UJI Citra Lestari', 1000000);
    $jurnalLama = app(\App\Domain\BusinessSupport\Services\JournalService::class)->post([
        'journal_date' => $TGL, 'source' => 'cash', 'status' => 'posted',
        'reference' => $bonLama->advance_number, 'description' => 'Pembayaran lama', 'created_by' => $user->id,
    ], [
        ['account_id' => $BANK_B, 'cost_center_id' => $cc, 'debit' => 1000000, 'credit' => 0, 'description' => 'Top-up kartu'],
        ['account_id' => $BANK_A, 'cost_center_id' => $cc, 'debit' => 0, 'credit' => 1000000, 'description' => 'Transfer'],
    ]);
    $bonLama->update([
        'status' => 'outstanding', 'payment_method' => 'debit_va', 'paid_date' => $TGL,
        'credit_card_id' => $kartu->id, 'cash_account_id' => $BANK_A, 'journal_id' => $jurnalLama->id,
        'va_expired_date' => sprintf('%04d-12-31', $TAHUN),
    ]);
    $bonLama->refresh();

    $lanjutkanAlokasi($bonLama);
    $svc->realize($bonLama, [
        'realized_date'    => $TGL,
        'defer_settlement' => true,
        'lines'            => [['expense_account_id' => $akunBeban, 'cost_center_id' => $cc, 'description' => 'Belanja', 'amount' => 400000]],
    ], $user->id);
    $bonLama->refresh();

    $jrLama = BsJournal::with('lines')->find($bonLama->realize_journal_id);
    $ok('bon lama tetap mengkredit AKUN KARTUNYA',
        (int) $jrLama->lines->firstWhere('credit', '>', 0)->account_id === (int) $kartu->account_id,
        (string) $jrLama->lines->firstWhere('credit', '>', 0)->account_id);
    $ok('acuan bon lama tetap nominal bonnya (1jt − 400rb)',
        abs((float) $bonLama->settlement - 600000) < 0.01, (string) $bonLama->settlement);
    // Masuk Saldo tetap tersedia untuk bon lama…
    $svc->masukSaldo($bonLama, $user->id);
    $bonLama->refresh();
    $ok('Masuk Saldo masih jalan untuk bon lama', $bonLama->settlement_status === 'card', (string) $bonLama->settlement_status);

    // …dan ditolak untuk bon model baru, sebab sisanya ada di Uang Muka Operasional.
    $bonBaru2 = $buatBon('UJI Dewi Anggraini', 400000);
    $svc->pay($bonBaru2, ['paid_date' => $TGL, 'payment_method' => 'debit_va',
        'va_expired_date' => sprintf('%04d-12-31', $TAHUN), 'credit_card_id' => $kartu->id], $user->id);
    $alokSvc->tambah($bonBaru2, ['alloc_date' => $TGL, 'description' => 'Alokasi', 'amount' => 400000, 'cash_account_id' => $BANK_A], $user->id);
    $bonBaru2->refresh();
    $lanjutkanAlokasi($bonBaru2);
    $svc->realize($bonBaru2, ['realized_date' => $TGL, 'defer_settlement' => true,
        'lines' => [['expense_account_id' => $akunBeban, 'cost_center_id' => $cc, 'description' => 'Belanja', 'amount' => 100000]]], $user->id);
    $bonBaru2->refresh();
    $kode = $tolak(fn () => $svc->masukSaldo($bonBaru2, $user->id));
    $ok('Masuk Saldo ditolak untuk bon model baru', $kode === 'ADV_MASUK_SALDO_OBSOLETE', (string) $kode);

    // Bon Debit VA tanpa alokasi tidak bisa direalisasi.
    $bonKosong = $buatBon('UJI Tanpa Alokasi', 100000);
    $svc->pay($bonKosong, ['paid_date' => $TGL, 'payment_method' => 'debit_va',
        'va_expired_date' => sprintf('%04d-12-31', $TAHUN), 'credit_card_id' => $kartu->id], $user->id);
    $bonKosong->refresh();
    $bonKosong->update(['allocation_done_at' => now()]);
    $bonKosong->refresh();
    $kode = $tolak(fn () => $svc->realize($bonKosong, ['realized_date' => $TGL, 'defer_settlement' => true,
        'lines' => [['expense_account_id' => $akunBeban, 'cost_center_id' => $cc, 'description' => 'X', 'amount' => 50000]]], $user->id));
    $ok('bon Debit VA tanpa alokasi tidak bisa direalisasi', $kode === 'ADV_NO_ALLOCATION', (string) $kode);

    echo "\n=== 6. SALDO KARTU PER BULAN: AWAL · PENAMBAHAN · PEMAKAIAN · SISA ===\n";

    $r = $closingSvc->ringkasan((int) $kartu->id, $TAHUN, $BULAN);
    /* Penambahan = SELURUH pembayaran Debit VA bulan ini pada kartu itu:
       2.000.000 (bon utama) + 250.000 (bon bank nyasar) + 400.000 + 100.000
       + 1.000.000 (bon model LAMA — ia pun ber-payment_method debit_va dan berkartu sama). */
    $penambahanHarap = 2000000 + 250000 + 400000 + 100000 + 1000000;
    $pemakaianHarap = 800000 + 400000;
    $ok('Saldo Awal nol karena bulan sebelumnya belum closing', abs($r['saldo_awal']) < 0.01, (string) $r['saldo_awal']);
    $ok('Penambahan = nominal pembayaran Debit VA bulan ini',
        abs($r['penambahan'] - $penambahanHarap) < 0.01, $r['penambahan'].' vs '.$penambahanHarap);
    $ok('Pemakaian = nominal Alokasi Kasbon bulan ini',
        abs($r['pemakaian'] - $pemakaianHarap) < 0.01, $r['pemakaian'].' vs '.$pemakaianHarap);
    $ok('Sisa Saldo = Awal + Penambahan − Pemakaian',
        abs($r['sisa_saldo'] - ($r['saldo_awal'] + $r['penambahan'] - $r['pemakaian'])) < 0.01, (string) $r['sisa_saldo']);
    $ok('bulan ini belum di-closing', $r['closed'] === false);

    // Pemakaian TIDAK boleh lagi dibaca dari mutasi akun kartunya: akun itu memang tidak
    // bermutasi lagi pada bon model baru.
    $kreditAkunKartu = (float) DB::table('bs_journal_lines')->where('account_id', $kartu->account_id)->sum('credit');
    $ok('kolom Pemakaian bukan SUM(credit) akun kartu', abs($r['pemakaian'] - $kreditAkunKartu) > 0.01,
        'kebetulan sama — mutasi akun kartu '.$kreditAkunKartu);

    echo "\n=== 7. CLOSING CREDIT CARD & SALDO AWAL BULAN BERIKUTNYA ===\n";

    $berikut = $closingSvc->ringkasan((int) $kartu->id, $TAHUN, $BULAN + 1);
    $ok('sebelum closing, saldo awal bulan berikutnya nol', abs($berikut['saldo_awal']) < 0.01, (string) $berikut['saldo_awal']);
    $ok('penandanya terbaca layar (prev_closed=false)', $berikut['prev_closed'] === false);

    $c = $closingSvc->close($kartu, $TAHUN, $BULAN, $user->id);
    $ok('closing menyimpan snapshot keempat angkanya',
        abs((float) $c->sisa_saldo - $r['sisa_saldo']) < 0.01, (string) $c->sisa_saldo);

    $berikut = $closingSvc->ringkasan((int) $kartu->id, $TAHUN, $BULAN + 1);
    $ok('sesudah closing, sisa menjadi Saldo Awal bulan berikutnya',
        abs($berikut['saldo_awal'] - $r['sisa_saldo']) < 0.01, $berikut['saldo_awal'].' vs '.$r['sisa_saldo']);
    $ok('prev_closed menjadi true', $berikut['prev_closed'] === true);

    $kode = $tolak(fn () => $closingSvc->close($kartu, $TAHUN, $BULAN, $user->id));
    $ok('closing dua kali pada bulan yang sama ditolak', $kode === 'CC_ALREADY_CLOSED', (string) $kode);

    /* Bulan yang sudah di-closing menampilkan angka BEKUNYA, bukan hitungan ulang. Koreksinya
       dilakukan pada bon yang MEMANG masih boleh dialokasikan ($bonKosong, belum direalisasi) —
       alokasi bon yang sudah direalisasi sengaja terkunci (diuji di tahap 3). */
    $alokSvc->tambah($bonKosong, ['alloc_date' => $TGL, 'description' => 'Koreksi sesudah closing', 'amount' => 100000, 'cash_account_id' => $BANK_A], $user->id);
    $rBeku = $closingSvc->ringkasan((int) $kartu->id, $TAHUN, $BULAN);
    $ok('angka bulan yang sudah di-closing tidak ikut berubah',
        abs($rBeku['pemakaian'] - $pemakaianHarap) < 0.01, $rBeku['pemakaian'].' vs '.$pemakaianHarap);

    $closing = BsCreditCardClosing::where('credit_card_id', $kartu->id)
        ->where('period_year', $TAHUN)->where('period_month', $BULAN)->first();
    $closingSvc->reopen($closing);
    $rBuka = $closingSvc->ringkasan((int) $kartu->id, $TAHUN, $BULAN);
    $ok('sesudah dibuka kembali, angkanya dihitung ulang',
        abs($rBuka['pemakaian'] - ($pemakaianHarap + 100000)) < 0.01, (string) $rBuka['pemakaian']);

    echo "\n=== 8. RIWAYAT DETAIL PER BULAN ===\n";

    $riwayat = $closingSvc->riwayat($kartu, $TAHUN, $BULAN + 1);
    $ok('riwayat memuat bulan mutasinya sampai bulan yang diminta', count($riwayat) >= 2, count($riwayat).' baris');
    $ok('tiap baris riwayat membawa keempat angkanya',
        isset($riwayat[0]['saldo_awal'], $riwayat[0]['penambahan'], $riwayat[0]['pemakaian'], $riwayat[0]['sisa_saldo']));
    $ok('baris riwayat berlabel bulan', ($riwayat[0]['label'] ?? '') !== '');

    echo "\n=== 9. LAYAR SET UP CREDIT CARD ===\n";

    /* Kartu KEDUA sengaja dibuat: dengan satu kartu saja, "riwayat kartu yang diminta" tidak
       terbedakan dari "riwayat kartu mana pun yang kebetulan pertama". */
    $kartu2 = BsCreditCard::create([
        'account_id' => $BANK_A, 'card_number' => 'UJI-VA-002', 'name' => 'KARTU UJI KEDUA',
        'is_active' => true, 'balance' => 0, 'created_by' => $user->id,
    ]);

    $props = app(CreditCardController::class)
        ->index($reqInertia(['year' => $TAHUN, 'month' => $BULAN, 'riwayat' => $kartu2->id]), $closingSvc)
        ->toResponse($reqInertia())->getData(true)['props'];
    $kartuLayar = collect($props['cards'])->firstWhere('id', $kartu->id);
    $ok('layar mengirim keempat kolom baru',
        isset($kartuLayar['saldo_awal'], $kartuLayar['penambahan'], $kartuLayar['pemakaian'], $kartuLayar['sisa_saldo']));
    $ok('angka layar = angka service', abs((float) $kartuLayar['pemakaian'] - $rBuka['pemakaian']) < 0.01);
    $ok('layar mengirim periode yang dipilih', (int) $props['period']['month'] === $BULAN && (int) $props['period']['year'] === $TAHUN);
    $ok('riwayat dikirim untuk kartu YANG DIMINTA, bukan kartu lain',
        ($props['riwayat']['card_id'] ?? null) === $kartu2->id,
        'diminta '.$kartu2->id.', dikirim '.json_encode($props['riwayat']['card_id'] ?? null));

    $tanpaRiwayat = app(CreditCardController::class)
        ->index($reqInertia(['year' => $TAHUN, 'month' => $BULAN]), $closingSvc)
        ->toResponse($reqInertia())->getData(true)['props'];
    $ok('tanpa permintaan riwayat, riwayatnya tidak dihitung sama sekali',
        $tanpaRiwayat['riwayat'] === null, json_encode($tanpaRiwayat['riwayat']));

} finally {
    DB::rollBack();
}

echo "\n────────────────────────────────\n";
echo "LULUS: $lulus   GAGAL: $gagal\n";
echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
