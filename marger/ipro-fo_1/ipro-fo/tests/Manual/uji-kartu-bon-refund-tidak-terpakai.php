<?php

/**
 * KARTU BON SEMENTARA — lima angka: Kasbon Awal · Realisasi · Refund · Tidak Terpakai · Outstanding.
 * Sekaligus layar baru SALDO DEBIT CARD (penambahan · pemakaian · sisa saldo, per nomor bon).
 *
 * APA YANG BERUBAH DAN KENAPA PERLU DIJAGA.
 *   1. Dulu Kartu Bon Sementara hanya punya "Nilai" dan outstanding = amount − realized_amount.
 *      Untuk bon Debit VA rumus itu SALAH: yang benar-benar keluar dari bank hanyalah total
 *      Alokasi Kasbon; sisanya masih duduk sebagai saldo kartu. Bon yang uangnya sudah kembali
 *      seluruhnya tetap tampak "outstanding" — dan jumlahnya tetap balance, jadi tidak ada satu
 *      pun galat yang memberi tahu.
 *   2. Refund TIDAK PERNAH SEBAGIAN: `settlement` ditulis sekali saat realisasi dan settle()
 *      selalu memakainya penuh. Karena itu Refund diturunkan dari settlement, bukan disimpan.
 *   3. Refund hanya diakui bila settlement_status = 'settled'. 'pending' berarti uangnya masih
 *      dipegang orang; mengakuinya lebih awal akan menihilkan outstanding atas uang itu.
 *   4. settlement_status = 'card' BUKAN refund: uangnya kembali ke saldo kartu, bukan ke kas.
 *      Ia masuk ke Tidak Terpakai.
 *   5. "Tidak Terpakai" hanya untuk bon yang sudah TUNTAS. Tanpa syarat itu rumus
 *      (awal − realisasi − refund) membuat outstanding SELALU nol — bon yang belum direalisasi
 *      pun akan terlihat lunas.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-kartu-bon-refund-tidak-terpakai.php';"
 */

use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Services\CashAdvanceAllocationService;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Domain\BusinessSupport\Support\SaldoBonSementara;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CashAdvanceController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
/** Bandingkan rupiah sebagai bilangan bulat — round() mengembalikan float, `=== 1000` selalu gagal. */
$rp = fn ($v) => (int) round((float) $v);

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

$akunUmOp = DB::table('bs_accounts')->where('code', config('business_support.gl_accounts.advance_op'))->value('id');
if (! $akunUmOp) { echo "Akun Uang Muka Operasional tidak ada — uji dihentikan.\n"; return; }

$bank = DB::table('bs_accounts')->where('is_postable', 1)->where('code', 'like', '101010%')
    ->whereNotIn('id', [$akunUmOp])->orderBy('code')->limit(2)->pluck('id')->all();
if (count($bank) < 2) { echo "Rekening bank tidak cukup — uji dihentikan.\n"; return; }
[$BANK_A, $BANK_B] = $bank;

$cc = DB::table('bs_cost_centers')->where('is_active', 1)->value('id');
if (! $cc) { echo "Cost center tidak ada — uji dihentikan.\n"; return; }

$beban = DB::table('bs_accounts')->where('is_postable', 1)->where('code', 'like', '5%')->orderBy('code')->value('id');
if (! $beban) { echo "Akun beban tidak ada — uji dihentikan.\n"; return; }

$TAHUN = 2034;   // masa khayalan, tidak bertabrakan dengan data nyata
$BULAN = 7;
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
    $js = app(\App\Domain\BusinessSupport\Services\JournalService::class);
    $js->ensurePeriod($TAHUN, $BULAN);
    $js->ensurePeriod($TAHUN, $BULAN + 1);

    $kartu = BsCreditCard::create([
        'account_id' => $BANK_B, 'card_number' => 'UJI-KARTU-9', 'name' => 'KARTU UJI REFUND',
        'is_active' => true, 'balance' => 0, 'expired_date' => sprintf('%04d-12-31', $TAHUN),
        'created_by' => $user->id,
    ]);

    $buatBon = fn (string $holder, float $amount, string $purpose) => $svc->issue([
        'advance_date'   => sprintf('%04d-%02d-01', $TAHUN, $BULAN),
        'holder'         => $holder,
        'purpose'        => $purpose,
        'amount'         => $amount,
        'cost_center_id' => $cc,
        'approval_mode'  => 'offline',
    ], $user->id);

    $realisasi = fn (BsCashAdvance $b, float $nilai) => $svc->realize($lanjutkanAlokasi($b), [
        'realized_date'     => $TGL,
        'defer_settlement'  => true,
        'lines'             => [['expense_account_id' => $beban, 'cost_center_id' => $cc, 'description' => 'Belanja uji', 'amount' => $nilai]],
    ], $user->id);

    echo "\n=== 1. BON DEBIT VA — persis contoh di layar (9.999.999 / 9.500.000) ===\n";

    /* Kasbon awal 9.999.999 masuk ke kartu; yang dialokasikan (benar-benar keluar bank) 9.611.111;
       realisasinya 9.500.000. Sisanya: 111.111 kembali ke kas (refund) dan 388.888 tidak pernah
       dialokasikan sama sekali (tidak terpakai). */
    $bonVa = $buatBon('UJI Dewi Anggraini', 9999999, 'Uji refund & tidak terpakai');
    $svc->pay($bonVa, ['paid_date' => $TGL, 'payment_method' => 'debit_va', 'va_expired_date' => sprintf('%04d-12-31', $TAHUN), 'credit_card_id' => $kartu->id], $user->id);
    $alokSvc->tambah($bonVa, ['alloc_date' => $TGL, 'description' => 'Alokasi 1', 'amount' => 9000000, 'cash_account_id' => $BANK_A], $user->id);
    $alokSvc->tambah($bonVa, ['alloc_date' => $TGL, 'description' => 'Alokasi 2', 'amount' => 611111,  'cash_account_id' => $BANK_B], $user->id);
    $realisasi($bonVa, 9500000);
    $bonVa->refresh();
    $ok('settlement = alokasi − realisasi = 111.111', $rp($bonVa->settlement) === 111111, (string) $bonVa->settlement);

    $svc->refund($bonVa, $TGL, $user->id, $BANK_A);
    $bonVa->refresh();
    $ok('settlement_status menjadi settled', $bonVa->settlement_status === 'settled', (string) $bonVa->settlement_status);

    $s = SaldoBonSementara::dariBon($bonVa);
    $ok('Kasbon Awal = 9.999.999', $rp($s['kasbon_awal']) === 9999999, (string) $s['kasbon_awal']);
    $ok('Realisasi = 9.500.000', $rp($s['realisasi']) === 9500000, (string) $s['realisasi']);
    $ok('Refund = 111.111 (kembali ke kas)', $rp($s['refund']) === 111111, (string) $s['refund']);
    $ok('Tidak Terpakai = 388.888 (tidak pernah dialokasikan)', $rp($s['tidak_terpakai']) === 388888, (string) $s['tidak_terpakai']);
    $ok('Outstanding = 0', $rp($s['outstanding']) === 0, (string) $s['outstanding']);
    $ok('Tidak Terpakai persis = Kasbon Awal − total alokasi',
        $rp($s['tidak_terpakai']) === 9999999 - 9611111, (string) $s['tidak_terpakai']);

    echo "\n=== 2. BON REGULER — tidak pernah punya Tidak Terpakai ===\n";

    /* Bon reguler: seluruh uangnya sudah keluar dari bank sejak dibayar, jadi acuannya = nominal
       bon dan sisa apa pun pasti kembali sebagai refund. Tidak Terpakai harus 0 — kalau tidak,
       refund akan terhitung dua kali. */
    $bonReg = $buatBon('UJI Andi Wijaya', 1000000, 'Uji bon reguler');
    $svc->pay($bonReg, ['paid_date' => $TGL, 'payment_method' => 'transfer', 'cash_account_id' => $BANK_A], $user->id);
    $realisasi($bonReg, 600000);
    $svc->refund($bonReg, $TGL, $user->id, $BANK_A);
    $bonReg->refresh();

    $s = SaldoBonSementara::dariBon($bonReg);
    $ok('Refund = 400.000', $rp($s['refund']) === 400000, (string) $s['refund']);
    $ok('Tidak Terpakai = 0 — seluruh uangnya memang sudah keluar', $rp($s['tidak_terpakai']) === 0, (string) $s['tidak_terpakai']);
    $ok('Outstanding = 0', $rp($s['outstanding']) === 0, (string) $s['outstanding']);

    echo "\n=== 3. PENYELESAIAN MASIH DITUNDA — sisanya TETAP outstanding ===\n";

    /* Inilah yang paling mudah salah: kalau refund diakui begitu settlement terisi (tanpa melihat
       statusnya), bon yang uangnya masih dipegang orang akan tampil lunas. */
    $bonPending = $buatBon('UJI Budi Santoso', 500000, 'Uji settlement pending');
    $svc->pay($bonPending, ['paid_date' => $TGL, 'payment_method' => 'transfer', 'cash_account_id' => $BANK_A], $user->id);
    $realisasi($bonPending, 300000);
    $bonPending->refresh();

    $ok('statusnya memang pending', $bonPending->settlement_status === 'pending', (string) $bonPending->settlement_status);
    $s = SaldoBonSementara::dariBon($bonPending);
    $ok('Refund = 0 selama belum diselesaikan', $rp($s['refund']) === 0, (string) $s['refund']);
    $ok('Tidak Terpakai = 0 selama belum tuntas', $rp($s['tidak_terpakai']) === 0, (string) $s['tidak_terpakai']);
    $ok('Outstanding = 200.000 — sisanya masih ditanggung pemegang bon',
        $rp($s['outstanding']) === 200000, (string) $s['outstanding']);

    echo "\n=== 4. BELUM DIREALISASI — seluruh nilainya outstanding ===\n";

    $bonBaru = $buatBon('UJI Citra Lestari', 750000, 'Uji belum realisasi');
    $bonBaru->refresh();
    $s = SaldoBonSementara::dariBon($bonBaru);
    $ok('settlement_status masih kosong', $bonBaru->settlement_status === null, (string) $bonBaru->settlement_status);
    $ok('Refund = 0', $rp($s['refund']) === 0, (string) $s['refund']);
    $ok('Tidak Terpakai = 0', $rp($s['tidak_terpakai']) === 0, (string) $s['tidak_terpakai']);
    $ok('Outstanding = 750.000 — utuh', $rp($s['outstanding']) === 750000, (string) $s['outstanding']);

    echo "\n=== 5. SISA DIKEMBALIKAN KE KARTU ('card') — bukan Refund, melainkan Tidak Terpakai ===\n";

    /* Masuk Saldo mengembalikan sisa ke saldo kartu TANPA jurnal: uangnya tidak pernah kembali ke
       bank. Menyebutnya "Refund" akan mengklaim kas bertambah padahal tidak.
       Jalur ini HANYA milik bon Debit VA MODEL LAMA (yang pembayarannya dulu berjurnal); model
       baru menolaknya dengan ADV_NOT_MASUK_SALDO karena sisanya ada di Uang Muka Operasional.
       Karena itu fixture-nya dibuat bermodel lama dengan sengaja — bukan lewat pay(). */
    $bonKartu = $buatBon('UJI Eko Prasetyo', 2000000, 'Uji masuk saldo kartu');
    $jurnalLama = $js->post([
        'journal_date' => $TGL, 'source' => 'cash', 'status' => 'posted',
        'reference' => $bonKartu->advance_number, 'description' => 'Pembayaran Debit VA (model lama)',
        'created_by' => $user->id,
    ], [
        ['account_id' => $kartu->account_id, 'cost_center_id' => $cc, 'debit' => 2000000, 'credit' => 0, 'description' => 'Top-up kartu'],
        ['account_id' => $BANK_A, 'cost_center_id' => $cc, 'debit' => 0, 'credit' => 2000000, 'description' => 'Transfer'],
    ]);
    $bonKartu->update([
        'status' => 'outstanding', 'payment_method' => 'debit_va', 'paid_date' => $TGL,
        'cash_account_id' => $BANK_A, 'credit_card_id' => $kartu->id, 'journal_id' => $jurnalLama->id,
        'va_expired_date' => sprintf('%04d-12-31', $TAHUN),
    ]);
    $bonKartu->refresh();
    $realisasi($bonKartu, 1700000);
    $svc->masukSaldo($bonKartu, $user->id);
    $bonKartu->refresh();

    $ok("settlement_status menjadi 'card'", $bonKartu->settlement_status === 'card', (string) $bonKartu->settlement_status);
    $s = SaldoBonSementara::dariBon($bonKartu);
    $ok('Refund = 0 — uangnya tidak kembali ke kas', $rp($s['refund']) === 0, (string) $s['refund']);
    $ok('Tidak Terpakai = 300.000 — kembali menjadi saldo kartu', $rp($s['tidak_terpakai']) === 300000, (string) $s['tidak_terpakai']);
    $ok('Outstanding = 0 — tidak lagi ditanggung pemegang bon', $rp($s['outstanding']) === 0, (string) $s['outstanding']);

    echo "\n=== 6. LAYAR KARTU BON SEMENTARA MENGIRIM KELIMA ANGKANYA ===\n";

    $props = app(CashAdvanceController::class)->card($reqInertia())->toResponse($reqInertia())->getData(true)['props'];
    $baris = collect($props['rows'])->firstWhere('no', $bonVa->advance_number);

    $ok('baris bon uji ada di layar', $baris !== null);
    $ok('kolom amount = Kasbon Awal 9.999.999', $baris && $rp($baris['amount']) === 9999999, json_encode($baris['amount'] ?? null));
    $ok('kolom refund dikirim = 111.111', $baris && $rp($baris['refund']) === 111111, json_encode($baris['refund'] ?? null));
    $ok('kolom unused (Tidak Terpakai) dikirim = 388.888', $baris && $rp($baris['unused']) === 388888, json_encode($baris['unused'] ?? null));
    $ok('kolom outstanding = 0', $baris && $rp($baris['outstanding']) === 0, json_encode($baris['outstanding'] ?? null));

    /* Baris yang belum tuntas harus tetap membawa outstanding-nya lewat jalur layar yang sama —
       bukan hanya lewat pemanggilan langsung ke penghitungnya. */
    $barisPending = collect($props['rows'])->firstWhere('no', $bonPending->advance_number);
    $ok('bon pending tetap outstanding 200.000 di layar',
        $barisPending && $rp($barisPending['outstanding']) === 200000, json_encode($barisPending['outstanding'] ?? null));

    echo "\n=== 7. SALDO DEBIT CARD — penambahan · pemakaian · sisa saldo per nomor bon ===\n";

    $propsSaldo = app(CashAdvanceController::class)->cardBalance($reqInertia())->toResponse($reqInertia())->getData(true)['props'];
    $b = collect($propsSaldo['rows'])->firstWhere('no', $bonVa->advance_number);

    $ok('bon Debit VA muncul di Saldo Debit Card', $b !== null);
    $ok('Penambahan = 9.999.999 (nominal pembayaran Debit VA)', $b && $rp($b['penambahan']) === 9999999, json_encode($b['penambahan'] ?? null));
    $ok('Pemakaian = 9.611.111 (total Alokasi Kasbon)', $b && $rp($b['pemakaian']) === 9611111, json_encode($b['pemakaian'] ?? null));
    $ok('Sisa Saldo = 388.888', $b && $rp($b['sisa']) === 388888, json_encode($b['sisa'] ?? null));
    $ok('Sisa Saldo di sini SAMA dengan Tidak Terpakai di Kartu Bon Sementara',
        $b && $baris && $rp($b['sisa']) === $rp($baris['unused']), json_encode([$b['sisa'] ?? null, $baris['unused'] ?? null]));

    $ok('riwayatnya memuat kedua alokasi', $b && count($b['riwayat']) === 2, json_encode(count($b['riwayat'] ?? [])));
    $ok('riwayat menjumlah persis sebesar Pemakaian',
        $b && $rp(collect($b['riwayat'])->sum('amount')) === $rp($b['pemakaian']),
        json_encode(collect($b['riwayat'] ?? [])->sum('amount')));
    $ok('riwayat menyebutkan bank pengeluarannya',
        $b && collect($b['riwayat'])->every(fn ($x) => ! empty($x['bank'])), json_encode($b['riwayat'][0] ?? null));

    /* Bon reguler TIDAK boleh ikut: ia tidak pernah menaruh saldo di kartu mana pun, dan
       memunculkannya membuat total Penambahan layar ini melebihi saldo kartu yang sebenarnya. */
    $ok('bon reguler tidak ikut muncul',
        collect($propsSaldo['rows'])->firstWhere('no', $bonReg->advance_number) === null);

    echo "\n=== 8. EKSPOR IKUT MEMBAWA KOLOM BARUNYA ===\n";

    /* Ekspor punya jalur query sendiri (cardExport), jadi layar yang benar tidak menjamin
       unduhannya benar. */
    $excel = app(CashAdvanceController::class)->cardExcel($reqInertia())->getContent();
    foreach (['Kasbon Awal', 'Refund', 'Tidak Terpakai', 'Outstanding'] as $judul) {
        $ok("Excel memuat kolom $judul", str_contains($excel, '<th>'.$judul.'</th>'), '');
    }
    $ok('Excel tidak lagi memakai judul kolom "Nilai"', ! str_contains($excel, '<th>Nilai</th>'));
    $ok('judul Excel merentang penuh 11 kolom', str_contains($excel, 'colspan="11"'), '');

    $pdf = app(CashAdvanceController::class)->cardPdf($reqInertia())->getContent();
    foreach (['Kasbon Awal', 'Refund', 'Tidak Terpakai'] as $judul) {
        $ok("PDF memuat kolom $judul", str_contains($pdf, $judul), '');
    }

    echo "\n──────────────────────────────────────────────────────────────────────────────\n";
    echo $gagal === 0 ? "SEMUA LULUS ($lulus)\n" : "ADA YANG GAGAL — lulus $lulus, gagal $gagal\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}
