<?php

/**
 * "Masuk Saldo" — sisa realisasi bon Debit VA dikembalikan ke saldo kartunya, TANPA jurnal.
 *
 * KENAPA TANPA JURNAL, DAN KENAPA ITU JUSTRU YANG BENAR. Pembayaran Debit VA MENDEBIT akun
 * kartu sebesar nominal bon; realisasinya MENGKREDIT sebesar yang terpakai. Sisanya karena itu
 * MASIH BERADA di akun kartu itu di buku besar — belum berpindah ke mana pun. Refund
 * memindahkannya kembali ke bank, karena itu ia berjurnal. Masuk Saldo membiarkannya tetap di
 * kartu dan hanya membuka kembali pemakaiannya. Menjurnalnya lagi berarti mencatat perpindahan
 * yang tidak terjadi, dan akun kartunya akan terkredit DUA KALI untuk uang yang sama.
 *
 * Itulah sebabnya berkas ini tidak cukup memeriksa "saldonya bertambah": ia mengunci bahwa
 * TIDAK ADA baris jurnal baru dan saldo GL akun kartunya TIDAK BERGERAK sedikit pun.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-masuk-saldo-kartu.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Models\BsCreditCardRequest;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Domain\BusinessSupport\Services\CreditCardService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CreditCardController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
$tolak = function (string $judul, string $kode, callable $aksi) use ($ok) {
    try { $aksi(); $ok($judul, false, 'tidak ditolak sama sekali'); }
    catch (BusinessRuleException $e) { $ok($judul, $e->errorCode() === $kode, "errorCode {$e->errorCode()}, diharap {$kode}"); }
    catch (\Throwable $e) { $ok($judul, false, 'galat lain: '.$e->getMessage()); }
};

$user = User::all()->first(fn ($u) => (bool) $u->canModule('cashbook')) ?? User::first();
auth()->setUser($user);
$svc = app(CashAdvanceService::class);
$svcCard = app(CreditCardService::class);

$akunKartu = BsAccount::where('code', '10101020210')->first(['id', 'code']);
$bank = BsAccount::where('is_postable', true)->where('type', 'asset')
    ->where('code', 'like', '1010102%')->where('code', '!=', '10101020210')->first(['id']);
$beban = BsAccount::where('is_postable', true)->where('type', 'expense')->first(['id']);
$cc = BsCostCenter::where('is_active', true)->first(['id']);

echo "\n=== PRASYARAT ===\n";
$ok('akun kartu 10101020210 ada', (bool) $akunKartu);
$ok('akun bank, beban & cost center ada', $bank && $beban && $cc);
if (! $akunKartu || ! $bank || ! $beban || ! $cc) { echo "\nPrasyarat kurang.\n"; return; }

/** Saldo GL akun kartu (debit − kredit). */
$glKartu = fn () => (float) BsJournalLine::where('account_id', $akunKartu->id)
    ->selectRaw('COALESCE(SUM(debit),0)-COALESCE(SUM(credit),0) s')->value('s');

DB::beginTransaction();
try {

$kartu = BsCreditCard::where('account_id', $akunKartu->id)->first();
$ok('kartu MANDIRI terpasang', (bool) $kartu);
if (! $kartu) { echo "\nKartu belum ada.\n"; DB::rollBack(); return; }
$kartu->update(['is_active' => true, 'balance' => 0, 'expired_date' => null]);

$NOMINAL = 10_000_000.0;
$TERPAKAI = 4_000_000.0;
$SISA = $NOMINAL - $TERPAKAI;

/**
 * Bon Debit VA MODEL LAMA yang sudah dibayar & direalisasi, menyisakan $SISA.
 *
 * KENAPA MODEL LAMA. "Masuk Saldo" bertumpu pada satu kenyataan: uangnya memang MASIH BERADA di
 * akun kartunya, sebab pembayarannya dulu mendebit akun itu. Sejak pembayaran Debit VA tidak lagi
 * berjurnal (uangnya keluar dari bank lewat ALOKASI KASBON, ke Uang Muka Operasional), kenyataan
 * itu hanya berlaku bagi bon yang TERLANJUR berjurnal — dan tombolnya memang hanya ditawarkan
 * untuk bon semacam itu. Bon model baru menyelesaikan sisanya lewat Refund; alurnya diuji di
 * tests/Manual/uji-kasbon-debit-va-alokasi.php.
 *
 * Jurnal pembayarannya karena itu dibuat di sini apa adanya, persis seperti yang tersimpan pada
 * data lama: Dr akun kartu / Cr bank.
 */
$buatBonVa = function (float $nominal, float $terpakai) use ($svc, $user, $kartu, $bank, $beban, $cc) {
    $bon = $svc->issue([
        'advance_date' => now()->toDateString(), 'holder' => 'UJI Masuk Saldo',
        'purpose' => 'UJI masuk saldo', 'amount' => $nominal,
        'approval_mode' => 'offline', 'cost_center_id' => $cc->id,
    ], $user?->id);

    $jurnalLama = app(\App\Domain\BusinessSupport\Services\JournalService::class)->post([
        'journal_date' => now()->toDateString(), 'source' => 'cash', 'status' => 'posted',
        'reference' => $bon->advance_number, 'description' => 'Pembayaran Debit VA (model lama)',
        'created_by' => $user?->id,
    ], [
        ['account_id' => $kartu->account_id, 'cost_center_id' => $cc->id, 'debit' => $nominal, 'credit' => 0, 'description' => 'Top-up kartu'],
        ['account_id' => $bank->id, 'cost_center_id' => $cc->id, 'debit' => 0, 'credit' => $nominal, 'description' => 'Transfer'],
    ]);
    $bon->update([
        'status' => 'outstanding', 'payment_method' => 'debit_va',
        'paid_date' => now()->toDateString(), 'cash_account_id' => $bank->id,
        'credit_card_id' => $kartu->id, 'journal_id' => $jurnalLama->id,
        'va_expired_date' => now()->addDays(30)->toDateString(),
    ]);
    $bon->refresh();

    $svc->realize($bon, [
        'realized_date' => now()->toDateString(), 'defer_settlement' => true,
        'lines' => [['expense_account_id' => $beban->id, 'cost_center_id' => $cc->id,
            'description' => 'uji pemakaian', 'amount' => $terpakai]],
    ], $user?->id);

    return $bon->refresh();
};

echo "\n=== 1. BON DEBIT VA BERSISA ===\n";
$bon = $buatBonVa($NOMINAL, $TERPAKAI);
$ok('bon berstatus realized', $bon->status === 'realized', (string) $bon->status);
$ok('sisanya '.number_format($SISA), round((float) $bon->settlement, 2) === $SISA, (string) $bon->settlement);
$ok('penyelesaiannya masih pending', $bon->settlement_status === 'pending', (string) $bon->settlement_status);

// Keadaan SEBELUM Masuk Saldo — inilah pembandingnya.
$glSebelum = $glKartu();
$saldoSebelum = (float) $kartu->refresh()->balance;
$jurnalSebelum = BsJournal::count();
$barisSebelum = BsJournalLine::count();
$ok('sisa bon masih tertinggal di akun kartu (GL)', $glSebelum >= $SISA - 0.01,
    'saldo GL akun kartu '.$glSebelum);

echo "\n=== 2. MASUK SALDO: SALDO NAIK, JURNAL TIDAK BERTAMBAH ===\n";
$svc->masukSaldo($bon, $user?->id);
$bon->refresh();
$kartu->refresh();

$ok('saldo kartu bertambah sebesar sisanya',
    round((float) $kartu->balance, 2) === round($saldoSebelum + $SISA, 2),
    'saldo '.$kartu->balance.' (sebelumnya '.$saldoSebelum.')');
// INI INTINYA.
$ok('TIDAK ada jurnal baru', BsJournal::count() === $jurnalSebelum,
    BsJournal::count().' vs '.$jurnalSebelum);
$ok('TIDAK ada baris jurnal baru', BsJournalLine::count() === $barisSebelum,
    BsJournalLine::count().' vs '.$barisSebelum);
$ok('saldo GL akun kartu TIDAK bergerak', round($glKartu(), 2) === round($glSebelum, 2),
    $glKartu().' vs '.$glSebelum);
$ok('bon tidak punya settle_journal_id', $bon->settle_journal_id === null);
$ok('status penyelesaiannya "card", bukan "settled"', $bon->settlement_status === 'card', (string) $bon->settlement_status);

echo "\n=== 3. JEJAKNYA TETAP ADA (bukan saldo yang berubah diam-diam) ===\n";
$permintaan = BsCreditCardRequest::where('source_type', CreditCardService::SUMBER_MASUK_SALDO)
    ->where('source_id', $bon->id)->first();
$ok('permintaan tercatat', (bool) $permintaan);
$ok('jenisnya penambahan saldo', $permintaan?->type === 'topup', (string) $permintaan?->type);
$ok('nilainya = sisa bon', round((float) $permintaan?->amount, 2) === $SISA, (string) $permintaan?->amount);
$ok('langsung berstatus disetujui', $permintaan?->status === 'approved', (string) $permintaan?->status);
$ok('tercatat siapa yang menyetujui', $permintaan?->approved_by !== null);
$ok('menyebut nomor bonnya', $permintaan?->reference === $bon->advance_number);
$ok('catatannya menyebut tanpa jurnal', str_contains((string) $permintaan?->note, 'tanpa jurnal'));

echo "\n=== 4. SALDONYA BENAR-BENAR BISA DIPAKAI PENGAJUAN BERIKUTNYA ===\n";
$ok('kartunya usable', $kartu->isUsable());
$bon2 = $svc->issue([
    'advance_date' => now()->toDateString(), 'holder' => 'UJI Pakai Sisa',
    'purpose' => 'UJI pakai sisa', 'amount' => 1_000_000,
    'approval_mode' => 'offline', 'cost_center_id' => $cc->id,
], $user?->id);
$svc->pay($bon2, [
    'paid_date' => now()->toDateString(), 'cash_account_id' => $bank->id,
    'payment_method' => 'debit_va', 'va_expired_date' => now()->addDays(30)->toDateString(),
    'credit_card_id' => $kartu->id,
], $user?->id);
$ok('pengajuan berikutnya memakai kartu yang sama diterima',
    $bon2->refresh()->status === 'outstanding', (string) $bon2->status);

echo "\n=== 5. GERBANGNYA ===\n";
$tolak('Masuk Saldo dua kali ditolak', 'ADV_NOT_PENDING', fn () => $svc->masukSaldo($bon, $user?->id));

// Bon REGULER tidak punya kartu penampung sisanya.
$bonReg = $svc->issue([
    'advance_date' => now()->toDateString(), 'holder' => 'UJI Reguler', 'purpose' => 'UJI reguler',
    'amount' => 2_000_000, 'approval_mode' => 'offline', 'cost_center_id' => $cc->id,
], $user?->id);
$svc->pay($bonReg, ['paid_date' => now()->toDateString(), 'cash_account_id' => $bank->id, 'payment_method' => 'reguler'], $user?->id);
$svc->realize($bonReg, [
    'realized_date' => now()->toDateString(), 'defer_settlement' => true,
    'lines' => [['expense_account_id' => $beban->id, 'cost_center_id' => $cc->id, 'description' => 'uji', 'amount' => 500_000]],
], $user?->id);
$tolak('bon REGULER ditolak', 'ADV_NOT_DEBIT_VA', fn () => $svc->masukSaldo($bonReg->refresh(), $user?->id));

// Bon Debit VA yang realisasinya MELEBIHI panjar (kekurangan) — tidak ada sisa untuk dimasukkan.
$bonKurang = $buatBonVa(1_000_000, 1_500_000);
$tolak('bon yang justru KEKURANGAN ditolak', 'ADV_NOT_REFUND', fn () => $svc->masukSaldo($bonKurang, $user?->id));

echo "\n=== 6. KOLOM PEMAKAIAN & SISA SALDO DI SET UP CREDIT CARD ===\n";

/* Permintaan Masuk Saldo yang DITOLAK sengaja diselipkan: tanpa baris seperti ini, saringan
   status 'approved' pada kolom Sisa Saldo tidak pernah teruji — Masuk Saldo selalu langsung
   menyetujui, jadi semua barisnya approved dan melepas saringannya tidak mengubah apa pun.
   Uji mutasi menemukan celah itu. */
$dibatalkan = $svcCard->request($kartu, [
    'type' => 'topup', 'amount' => 777_000,
    'source_type' => CreditCardService::SUMBER_MASUK_SALDO, 'source_id' => 999999,
    'reference' => 'UJI-DITOLAK', 'note' => 'uji saringan status',
], $user?->id);
$svcCard->reject($dibatalkan, $user?->id, 'uji');
$ok('ada permintaan Masuk Saldo yang ditolak sebagai pembanding',
    $dibatalkan->refresh()->status === 'rejected');
$ctl = app(CreditCardController::class);
$rq = \Illuminate\Http\Request::create('/business-support/credit-cards', 'GET');
$rq->setUserResolver(fn () => $user);
$rq->headers->set('X-Inertia', 'true');
$rq->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
$props = json_decode((string) $ctl->index($rq, app(\App\Domain\BusinessSupport\Services\CreditCardClosingService::class))
    ->toResponse($rq)->getContent(), true)['props'] ?? [];
$barisKartu = collect($props['cards'] ?? [])->firstWhere('id', $kartu->id);

$ok('kartunya ada di layar', (bool) $barisKartu);
/* Kolom kartu kini EMPAT dan semuanya milik SATU BULAN: Saldo Awal · Penambahan · Pemakaian ·
   Sisa Saldo. Perhitungannya sendiri diuji di tests/Manual/uji-kasbon-debit-va-alokasi.php;
   yang dijaga di sini adalah akibatnya bagi Masuk Saldo. */
foreach (['saldo_awal', 'penambahan', 'pemakaian', 'sisa_saldo'] as $kolom) {
    $ok("kolom {$kolom} dikirim ke layar", array_key_exists($kolom, $barisKartu ?? []));
}

/* MASUK SALDO TIDAK MENAMBAH "Penambahan" KARTUNYA — dan itu memang benar. Penambahan berasal
   dari nominal PEMBAYARAN Debit VA; sisa realisasi yang dikembalikan ke kartu adalah uang yang
   sama, yang memang tidak pernah keluar dari akun kartunya. Menghitungnya lagi berarti satu
   rupiah masuk dua kali, dan sisa saldonya menggelembung tanpa satu galat pun. */
$permintaanMasuk = BsCreditCardRequest::where('source_type', CreditCardService::SUMBER_MASUK_SALDO)
    ->where('credit_card_id', $kartu->id)->where('status', 'approved')->sum('amount');
$ok('ada permintaan Masuk Saldo yang disetujui sebagai bahan uji',
    round((float) $permintaanMasuk, 2) === $SISA, (string) $permintaanMasuk);
$ok('Masuk Saldo TIDAK ikut terhitung sebagai Penambahan kartu',
    round((float) ($barisKartu['penambahan'] ?? -1), 2) !== round($SISA, 2),
    'penambahan '.($barisKartu['penambahan'] ?? 'null').' — sama dengan nilai Masuk Saldo, berarti terhitung dua kali');
$ok('Pemakaian kartu tidak lagi dibaca dari mutasi akun kartunya',
    round((float) ($barisKartu['pemakaian'] ?? -1), 2) === 0.0,
    'bon uji ini tidak punya Alokasi Kasbon, jadi pemakaiannya nol: '.($barisKartu['pemakaian'] ?? 'null'));


echo "\n=== 7. SUMBER LAYAR ===\n";
$vueBon = file_get_contents(resource_path('js/Pages/BusinessSupport/CashBook/Advances/Show.vue')) ?: '';

/* Diperiksa pada TOMBOLNYA, bukan pada untai ">Masuk Saldo<" di mana pun: kalimat penjelas di
   bawahnya memuat "<b>Masuk Saldo</b>" yang sama persis, jadi menghapus tombolnya sekalipun
   akan tetap lulus. Uji mutasi menemukan celah ini. */
$tombolMasuk = (bool) preg_match('/<Button[^>]*v-if="bisaMasukSaldo"[^>]*@click="doMasukSaldo"[^>]*>\s*Masuk Saldo\s*<\/Button>/', $vueBon);
$ok('tombol Masuk Saldo ada, bergerbang bisaMasukSaldo', $tombolMasuk);
$ok('tombolnya berdampingan dengan Refund Sisa',
    (bool) preg_match('/>Refund Sisa<\/Button>[\s\S]{0,900}v-if="bisaMasukSaldo"/', $vueBon));

/* Syaratnya dibaca dari BADAN computed-nya sendiri. Untai "payment_method === 'debit_va'"
   muncul 8 kali di berkas ini (blok pembayaran, ringkasan, keterangan jurnal), jadi mencarinya
   se-berkas akan lulus meski gerbang tombolnya diubah menjadi apa saja. */
$badanGerbang = '';
if (preg_match('/const bisaMasukSaldo = computed\([\s\S]*?\);/', $vueBon, $m)) {
    $badanGerbang = $m[0];
}
$ok('badan gerbang bisaMasukSaldo terbaca', $badanGerbang !== '');
$ok('syarat tombolnya sama dengan server',
    str_contains($badanGerbang, "payment_method === 'debit_va'")
    && str_contains($badanGerbang, 'credit_card_id')
    && str_contains($badanGerbang, 'settlement) > 0'),
    $badanGerbang);
$ok('konfirmasinya menyebut tanpa jurnal', str_contains($vueBon, 'TIDAK ada jurnal tambahan'));

$vueKartu = file_get_contents(resource_path('js/Pages/BusinessSupport/CashBook/CreditCards/Index.vue')) ?: '';
/* Kolom kartu kini EMPAT dan berurutan membentuk rumusnya: Saldo Awal + Penambahan − Pemakaian
   = Sisa Saldo. Urutannya ikut dikunci — angka yang bergeser satu kolom tidak menimbulkan galat
   apa pun, hanya laporan yang salah. */
$ok('keempat kepala kolomnya ada & berurutan sesuai rumusnya',
    (bool) preg_match('/>Saldo Awal<\/th>\s*<th[^>]*>Penambahan<\/th>\s*<th[^>]*>Pemakaian<\/th>\s*<th[^>]*>Sisa Saldo<\/th>/', $vueKartu));
$ok('kolom "Saldo" berjalan yang lama sudah tidak ada lagi',
    ! preg_match('/<th[^>]*>Saldo<\/th>/', $vueKartu));

} finally {
    DB::rollBack();
}

echo "\n=== HASIL ===\n";
echo "  lulus: $lulus · gagal: $gagal\n";
echo $gagal === 0 ? "  SEMUA LULUS\n" : "  ADA YANG GAGAL\n";
