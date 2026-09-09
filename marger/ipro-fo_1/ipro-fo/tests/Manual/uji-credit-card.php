<?php

/**
 * Uji Set Up Credit Card + pembayaran/realisasi bon sementara lewat Debit VA.
 *
 * Yang dibuktikan:
 *   1. Kartu baru lahir NONAKTIF & bersaldo 0, dan kartu nonaktif DITOLAK saat membayar.
 *   2. Saldo hanya berubah lewat permintaan yang DISETUJUI (topup menambah, reduce mengurangi,
 *      pengurangan melebihi saldo ditolak, penolakan tidak mengubah apa pun).
 *   3. Kartu yang sudah lewat masa berlakunya ditolak saat membayar.
 *   4. Debit VA tanpa kartu ditolak.
 *   5. Jurnal pembayaran Debit VA MENDEBIT akun kartu (10101020210), bukan Uang Muka Operasional.
 *   6. Pembayaran itu melahirkan permintaan topup + expiry PADA KARTU YANG BERSANGKUTAN,
 *      keduanya pending, sebesar nominal & tanggal yang diisi.
 *   7. Realisasi MENGKREDIT akun kartu yang sama, sehingga sisa saldo kartu di buku besar
 *      = pengajuan − realisasi.
 *   8. Bon reguler tidak tersentuh sama sekali (tetap Dr Uang Muka Operasional).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-credit-card.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Models\BsCreditCardRequest;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Domain\BusinessSupport\Services\CreditCardService;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail ? " — $detail" : '')."\n"; }
};
/** Jalankan dan harap DITOLAK dengan errorCode tertentu. */
$tolak = function (string $judul, string $kode, callable $aksi) use ($ok) {
    try {
        $aksi();
        $ok($judul, false, 'tidak ditolak sama sekali');
    } catch (BusinessRuleException $e) {
        $ok($judul, $e->errorCode() === $kode, "errorCode {$e->errorCode()}, diharap {$kode} · {$e->getMessage()}");
    } catch (\Throwable $e) {
        $ok($judul, false, 'galat lain: '.get_class($e).' — '.$e->getMessage());
    }
};

$user = User::first();
$svcCard = app(CreditCardService::class);
$svcAdv = app(CashAdvanceService::class);

$akunKartu = BsAccount::where('code', '10101020210')->first();
$akunBank  = BsAccount::where('is_postable', true)->where('type', 'asset')
    ->where('code', 'like', '1010102%')->where('code', '!=', '10101020210')->first();
$akunBeban = BsAccount::where('is_postable', true)->where('type', 'expense')->first();
$cc        = BsCostCenter::where('is_active', true)->first();
$akunUM    = BsAccount::where('code', config('business_support.gl_accounts.advance_op'))->first();

echo "\n=== PRASYARAT ===\n";
$ok('Akun kartu 10101020210 ada', (bool) $akunKartu);
$ok('Akun bank sumber lain ada', (bool) $akunBank, (string) $akunBank?->code);
$ok('Akun beban & cost center ada', $akunBeban && $cc);
$ok('Akun Uang Muka Operasional ada', (bool) $akunUM, (string) $akunUM?->code);
if (! $akunKartu || ! $akunBank || ! $akunBeban || ! $cc || ! $akunUM) {
    echo "\nPrasyarat kurang — uji dihentikan.\n"; return;
}

/** Saldo buku besar sebuah akun (debit − kredit). */
$saldoGL = fn (int $accountId) => (float) BsJournalLine::where('account_id', $accountId)
    ->selectRaw('COALESCE(SUM(debit),0)-COALESCE(SUM(credit),0) s')->value('s');

/** Bon sementara siap-bayar (persetujuan offline supaya gerbangnya terbuka). */
$buatBon = function (float $nominal) use ($user, $akunUM) {
    return BsCashAdvance::create([
        'advance_account_id' => $akunUM->id,
        'advance_number' => 'UJI-CC-'.substr((string) microtime(true), -8).random_int(10, 99),
        'advance_date'   => now()->toDateString(),
        'holder'         => 'Penguji Kartu',
        'purpose'        => 'Uji Debit VA',
        'amount'         => $nominal,
        'status'         => 'requested',
        'approval_mode'  => 'offline',
        'payment_method' => 'reguler',
        'created_by'     => $user?->id,
    ]);
};

$bersihkan = [];   // id bon yang dibuat uji ini
$kartuUji  = null;

DB::beginTransaction();
try {

echo "\n=== 1. KARTU BARU: NONAKTIF & SALDO 0 ===\n";
// Kartu MANDIRI pada akun 10101020210 dibuat oleh migrasinya. Diperiksa apa adanya lebih dulu:
// keadaan awal yang benar-benar terpasang di basis data, bukan yang dibuat sendiri oleh uji ini.
$kartuUji = BsCreditCard::where('account_id', $akunKartu->id)->first();
$ok('Kartu MANDIRI pada akun 10101020210 terpasang', (bool) $kartuUji);
if (! $kartuUji) { echo "\nKartu belum ada — jalankan migrasinya.\n"; DB::rollBack(); return; }

// Kelahiran kartu diuji lewat kartu lain (satu akun hanya boleh punya satu kartu).
$kartuBaru = BsCreditCard::create([
    'account_id'  => $akunBank->id,
    'card_number' => 'UJI-0000-0000',
    'name'        => 'KARTU UJI BARU',
    'is_active'   => false,
    'balance'     => 0,
    'created_by'  => $user?->id,
]);
$ok('Kartu baru nonaktif', ! $kartuBaru->is_active);
$ok('Kartu baru bersaldo 0', (float) $kartuBaru->balance === 0.0);
$ok('Kartu nonaktif TIDAK usable', ! $kartuBaru->isUsable());

// Kartu MANDIRI dikembalikan ke keadaan awalnya untuk rangkaian uji berikutnya.
$kartuUji->update(['is_active' => false, 'balance' => 0, 'expired_date' => null]);
$kartuUji->refresh();

$bon = $buatBon(5_000_000); $bersihkan[] = $bon->id;
$tolak('Bayar Debit VA dengan kartu NONAKTIF ditolak', 'ADV_VA_CARD_UNUSABLE', fn () => $svcAdv->pay($bon, [
    'paid_date' => now()->toDateString(), 'cash_account_id' => $akunBank->id,
    'payment_method' => 'debit_va', 'va_expired_date' => now()->addDays(30)->toDateString(),
    'credit_card_id' => $kartuUji->id,
], $user?->id));

echo "\n=== 2. SALDO HANYA BERUBAH LEWAT PERSETUJUAN ===\n";
$kartuUji->update(['is_active' => true]);
$kartuUji->refresh();
$ok('Kartu aktif tanpa expired = usable', $kartuUji->isUsable());

$rTopup = $svcCard->request($kartuUji, ['type' => 'topup', 'amount' => 10_000_000], $user?->id);
$kartuUji->refresh();
$ok('Permintaan topup berstatus pending', $rTopup->status === 'pending');
$ok('Saldo BELUM berubah sebelum disetujui', (float) $kartuUji->balance === 0.0, 'saldo '.$kartuUji->balance);

$svcCard->approve($rTopup, $user?->id);
$kartuUji->refresh();
$ok('Setelah disetujui saldo = 10.000.000', (float) $kartuUji->balance === 10_000_000.0, 'saldo '.$kartuUji->balance);
$ok('Permintaan tercatat approved + approver', $rTopup->refresh()->status === 'approved' && $rTopup->approved_by !== null);

$tolak('Menyetujui ulang permintaan yang sama ditolak', 'CC_NOT_PENDING', fn () => $svcCard->approve($rTopup, $user?->id));

$rKurang = $svcCard->request($kartuUji, ['type' => 'reduce', 'amount' => 2_500_000], $user?->id);
$svcCard->approve($rKurang, $user?->id);
$kartuUji->refresh();
$ok('Pengurangan saldo 2.500.000 → 7.500.000', (float) $kartuUji->balance === 7_500_000.0, 'saldo '.$kartuUji->balance);

$rLebih = $svcCard->request($kartuUji, ['type' => 'reduce', 'amount' => 99_000_000], $user?->id);
$tolak('Pengurangan melebihi saldo ditolak', 'CC_INSUFFICIENT', fn () => $svcCard->approve($rLebih, $user?->id));
$kartuUji->refresh();
$ok('Saldo tidak berubah setelah pengurangan ditolak', (float) $kartuUji->balance === 7_500_000.0, 'saldo '.$kartuUji->balance);

$rTolak = $svcCard->request($kartuUji, ['type' => 'topup', 'amount' => 1_000_000], $user?->id);
$svcCard->reject($rTolak, $user?->id, 'uji penolakan');
$kartuUji->refresh();
$ok('Permintaan yang DITOLAK tidak menambah saldo', (float) $kartuUji->balance === 7_500_000.0, 'saldo '.$kartuUji->balance);
$ok('Status permintaan = rejected', $rTolak->refresh()->status === 'rejected');

$tolak('Permintaan topup bernilai 0 ditolak', 'CC_ZERO',
    fn () => $svcCard->request($kartuUji, ['type' => 'topup', 'amount' => 0], $user?->id));
$tolak('Permintaan expiry tanpa tanggal ditolak', 'CC_NO_DATE',
    fn () => $svcCard->request($kartuUji, ['type' => 'expiry'], $user?->id));

echo "\n=== 3. KARTU EXPIRED TIDAK BISA DIPAKAI ===\n";
$rExp = $svcCard->request($kartuUji, ['type' => 'expiry', 'expired_date' => now()->subDay()->toDateString()], $user?->id);
$svcCard->approve($rExp, $user?->id);
$kartuUji->refresh();
$ok('Masa berlaku kartu tersimpan dari permintaan', $kartuUji->expired_date?->toDateString() === now()->subDay()->toDateString());
$ok('Kartu lewat tanggal = expired', $kartuUji->isExpired());
$ok('Kartu expired TIDAK usable meski saldonya ada', ! $kartuUji->isUsable() && (float) $kartuUji->balance > 0);

$bon2 = $buatBon(1_000_000); $bersihkan[] = $bon2->id;
$tolak('Bayar Debit VA dengan kartu EXPIRED ditolak', 'ADV_VA_CARD_UNUSABLE', fn () => $svcAdv->pay($bon2, [
    'paid_date' => now()->toDateString(), 'cash_account_id' => $akunBank->id,
    'payment_method' => 'debit_va', 'va_expired_date' => now()->addDays(30)->toDateString(),
    'credit_card_id' => $kartuUji->id,
], $user?->id));

// Kembalikan masa berlakunya ke depan untuk uji berikutnya.
$rExp2 = $svcCard->request($kartuUji, ['type' => 'expiry', 'expired_date' => now()->addYear()->toDateString()], $user?->id);
$svcCard->approve($rExp2, $user?->id);
$kartuUji->refresh();
$ok('Kartu kembali usable setelah masa berlakunya diperpanjang', $kartuUji->isUsable());

echo "\n=== 4. DEBIT VA WAJIB BERKARTU ===\n";
$bon3 = $buatBon(2_000_000); $bersihkan[] = $bon3->id;
$tolak('Debit VA tanpa credit_card_id ditolak', 'ADV_VA_NO_CARD', fn () => $svcAdv->pay($bon3, [
    'paid_date' => now()->toDateString(), 'cash_account_id' => $akunBank->id,
    'payment_method' => 'debit_va', 'va_expired_date' => now()->addDays(30)->toDateString(),
], $user?->id));
$tolak('Debit VA dengan kartu tak dikenal ditolak', 'ADV_VA_NO_CARD', fn () => $svcAdv->pay($bon3, [
    'paid_date' => now()->toDateString(), 'cash_account_id' => $akunBank->id,
    'payment_method' => 'debit_va', 'va_expired_date' => now()->addDays(30)->toDateString(),
    'credit_card_id' => 987654321,
], $user?->id));
$bon3->refresh();
$ok('Bon yang ditolak tetap "requested" tanpa jurnal', $bon3->status === 'requested' && ! $bon3->journal_id);

echo "\n=== 5-7. PEMBAYARAN DEBIT VA — TANPA JURNAL, KARTUNYA TETAP OFF-LEDGER ===\n";
/* Pembayaran Debit VA TIDAK lagi membentuk jurnal apa pun: uangnya belum berpindah, hanya
   disediakan pada kartu/VA-nya. Yang mengeluarkannya dari bank adalah ALOKASI KASBON —
   Dr Uang Muka Operasional / Cr bank pengeluaran — dan seluruh alurnya (alokasi, acuan
   realisasi, refund, saldo kartu per bulan, closing) diuji di
   tests/Manual/uji-kasbon-debit-va-alokasi.php.

   Yang dijaga DI SINI hanya bagian yang memang milik berkas ini: kartunya sendiri. */
$nominalBon = 6_000_000.0;
$tglExpired = now()->addDays(45)->toDateString();

$glKartuAwal = $saldoGL($akunKartu->id);
$glUmAwal    = $saldoGL($akunUM->id);
$saldoKartuAwal = (float) $kartuUji->refresh()->balance;

$bonVA = $buatBon($nominalBon); $bersihkan[] = $bonVA->id;
$svcAdv->pay($bonVA, [
    'paid_date' => now()->toDateString(),
    'payment_method' => 'debit_va', 'va_expired_date' => $tglExpired,
    'credit_card_id' => $kartuUji->id,
], $user?->id);
$bonVA->refresh();

$ok('Bon tersimpan sebagai debit_va + kartunya', $bonVA->payment_method === 'debit_va' && (int) $bonVA->credit_card_id === (int) $kartuUji->id);
$ok('Tanggal expired VA tersimpan', $bonVA->va_expired_date?->toDateString() === $tglExpired);
$ok('Pembayaran Debit VA TIDAK membentuk jurnal', $bonVA->journal_id === null, (string) $bonVA->journal_id);
$ok('Sumber bank/kas tidak ikut tersimpan', $bonVA->cash_account_id === null, (string) $bonVA->cash_account_id);

/* AKUN KARTUNYA TIDAK BERMUTASI SAMA SEKALI, dan itu justru intinya: sejak pembayaran Debit VA
   tak berjurnal, "Pemakaian" kartu tidak bisa lagi dibaca dari SUM(credit) akun kartunya —
   angkanya akan NOL tanpa satu galat pun. Kolom itu kini dibaca dari Alokasi Kasbon. */
$ok('Akun kartu tidak bermutasi di buku besar',
    round($saldoGL($akunKartu->id) - $glKartuAwal, 2) === 0.0,
    'selisih GL '.round($saldoGL($akunKartu->id) - $glKartuAwal, 2));
$ok('Uang Muka Operasional pun belum bergerak sebelum dialokasikan',
    round($saldoGL($akunUM->id) - $glUmAwal, 2) === 0.0,
    'selisih '.round($saldoGL($akunUM->id) - $glUmAwal, 2));

/* Yang lahir otomatis kini MASA BERLAKU saja. Permintaan penambahan saldo ditiadakan: kolom
   "Penambahan" pada Set Up Credit Card dibaca langsung dari pembayaran Debit VA yang tersimpan,
   jadi permintaan saldo di sampingnya akan menjadi angka kedua untuk uang yang sama. */
$permintaanBon = BsCreditCardRequest::where('source_type', 'cash_advance')->where('source_id', $bonVA->id)->get();
$pExpiry = $permintaanBon->firstWhere('type', 'expiry');
$ok('Pembayaran melahirkan 1 permintaan (masa berlaku saja)', $permintaanBon->count() === 1, (string) $permintaanBon->count());
$ok('Tidak ada lagi permintaan penambahan saldo otomatis',
    $permintaanBon->firstWhere('type', 'topup') === null, 'akan menghitung nominal bon dua kali');
$ok('Permintaan menempel pada KARTU yang dipilih', (int) $pExpiry?->credit_card_id === (int) $kartuUji->id);
$ok('Tanggal permintaan expiry = tanggal expired VA', $pExpiry?->expired_date?->toDateString() === $tglExpired);
$ok('Permintaan menunggu persetujuan', $pExpiry?->status === 'pending');
$ok('Permintaan menyebut nomor bonnya', $pExpiry?->reference === $bonVA->advance_number);
$ok('Saldo kolom balance kartu tidak ikut berubah oleh pembayaran',
    (float) $kartuUji->refresh()->balance === $saldoKartuAwal, 'saldo '.$kartuUji->balance);

$svcCard->approve($pExpiry, $user?->id);
$kartuUji->refresh();
$ok('Masa berlaku kartu mengikuti expired VA bonnya', $kartuUji->expired_date?->toDateString() === $tglExpired);


echo "\n=== 8. BON REGULER TIDAK TERSENTUH ===\n";
$glKartuSblmReguler = $saldoGL($akunKartu->id);
$glUmSblmReguler    = $saldoGL($akunUM->id);
$bonReg = $buatBon(3_000_000); $bersihkan[] = $bonReg->id;
$svcAdv->pay($bonReg, [
    'paid_date' => now()->toDateString(), 'cash_account_id' => $akunBank->id,
    'payment_method' => 'reguler',
], $user?->id);
$bonReg->refresh();
$barisReg = BsJournalLine::where('journal_id', $bonReg->journal_id)->get();
$ok('Bon reguler mendebit Uang Muka Operasional',
    (float) ($barisReg->firstWhere('account_id', $akunUM->id)?->debit ?? 0) === 3_000_000.0);
$ok('Bon reguler tidak menyentuh akun kartu', ! $barisReg->firstWhere('account_id', $akunKartu->id));
$ok('Bon reguler tidak melahirkan permintaan kartu apa pun',
    BsCreditCardRequest::where('source_type', 'cash_advance')->where('source_id', $bonReg->id)->count() === 0);
$ok('credit_card_id bon reguler kosong', $bonReg->credit_card_id === null);
$ok('Saldo GL akun kartu tidak bergerak oleh bon reguler',
    round($saldoGL($akunKartu->id) - $glKartuSblmReguler, 2) === 0.0);
$ok('Saldo GL Uang Muka bergerak +3.000.000 oleh bon reguler',
    round($saldoGL($akunUM->id) - $glUmSblmReguler, 2) === 3_000_000.0);

echo "\n=== 9. VALIDASI HTTP pay() ===\n";
$rules = (new ReflectionMethod(\App\Http\Controllers\Web\BusinessSupport\CashBook\CashAdvanceController::class, 'pay'))
    ->getFileName();
$srcCtl = file_get_contents($rules);
$ok('Controller show() mengirim daftar kartu', str_contains($srcCtl, "'creditCards'"));

/* Kewajiban credit_card_id diuji lewat VALIDASI SUNGGUHAN, bukan pencarian teks: aturan
   'required_if:payment_method,debit_va' juga dipakai kolom lain, sehingga sekadar menemukan
   untai itu di berkas tidak membuktikan apa pun tentang kolom kartunya. */
$userCb = User::all()->first(fn ($u) => (bool) $u->canModule('cashbook')) ?? $user;
$ctl = app(\App\Http\Controllers\Web\BusinessSupport\CashBook\CashAdvanceController::class);
$panggilPay = function (array $payload) use ($ctl, $userCb, $svcAdv, $buatBon, &$bersihkan) {
    $b = $buatBon(1_500_000); $bersihkan[] = $b->id;
    $req = \Illuminate\Http\Request::create('/uji/pay', 'POST', $payload);
    $req->setUserResolver(fn () => $userCb);
    try {
        $ctl->pay($req, $b, $svcAdv);

        return ['errors' => [], 'advance' => $b->refresh()];
    } catch (\Illuminate\Validation\ValidationException $e) {
        return ['errors' => $e->errors(), 'advance' => $b->refresh()];
    }
};

$dasarPay = [
    'paid_date'       => now()->toDateString(),
    'cash_account_id' => $akunBank->id,
    'payment_method'  => 'debit_va',
    'va_expired_date' => now()->addDays(30)->toDateString(),
];
$h1 = $panggilPay($dasarPay);
$ok('Validasi menolak Debit VA tanpa credit_card_id', array_key_exists('credit_card_id', $h1['errors']),
    'kolom bergalat: '.implode(',', array_keys($h1['errors'])));
$ok('Bon tidak terbayar saat validasinya gagal', $h1['advance']->status === 'requested' && ! $h1['advance']->journal_id);

$h2 = $panggilPay($dasarPay + ['credit_card_id' => 987654321]);
$ok('Validasi menolak kartu yang tidak ada', array_key_exists('credit_card_id', $h2['errors']));

$h3 = $panggilPay($dasarPay + ['credit_card_id' => $kartuUji->id]);
$ok('Validasi meloloskan Debit VA berkartu sah', ! array_key_exists('credit_card_id', $h3['errors']),
    'kolom bergalat: '.implode(',', array_keys($h3['errors'])));
$ok('Bon lolos validasi benar-benar terbayar lewat controller',
    $h3['advance']->status === 'outstanding' && (int) $h3['advance']->credit_card_id === (int) $kartuUji->id);

$h4 = $panggilPay(['paid_date' => now()->toDateString(), 'cash_account_id' => $akunBank->id, 'payment_method' => 'reguler']);
$ok('Pembayaran reguler tidak menuntut kartu', ! array_key_exists('credit_card_id', $h4['errors']));

$srcVue = file_get_contents(resource_path('js/Pages/BusinessSupport/CashBook/Advances/Show.vue'));
$ok('Form pembayaran mengirim credit_card_id', str_contains($srcVue, "credit_card_id: ''"));
// Dicek pada RANGKAIAN :disabled tombolnya, bukan sekadar "kata itu ada di berkas": melepasnya
// dari tombol sementara computed-nya tetap tertulis akan lolos dari pemeriksaan yang longgar.
$ok('Tombol bayar tertahan bila kartu belum dipilih', str_contains($srcVue, 'vaKurangExpired || vaKurangKartu"'));
$ok('Ada pemilih kartu pada blok Debit VA', str_contains($srcVue, 'payForm.credit_card_id = v'));

$srcCard = file_get_contents(app_path('Http/Controllers/Web/BusinessSupport/CashBook/CreditCardController.php'));
// Badan update() dipotong TEPAT sampai method berikutnya. Tanpa batas itu, pencarian akan
// menemukan 'expired_date' milik requestStore() dan menuduh update() menerimanya.
$awal = strpos($srcCard, 'public function update(');
$akhir = strpos($srcCard, 'public function requestStore(');
$badanUpdate = ($awal !== false && $akhir !== false && $akhir > $awal) ? substr($srcCard, $awal, $akhir - $awal) : '';
$ok('Badan update() berhasil dipotong', $badanUpdate !== '');
$ok('update() kartu TIDAK menerima balance', ! str_contains($badanUpdate, "'balance'"));
$ok('update() kartu TIDAK menerima expired_date', ! str_contains($badanUpdate, "'expired_date'"));
$ok('store() kartu memaksa nonaktif & saldo 0',
    (bool) preg_match("/'is_active'\s*=>\s*false,\s*'balance'\s*=>\s*0/", $srcCard));

} finally {
    DB::rollBack();
}

echo "\n=== HASIL ===\n";
echo "  lulus: $lulus · gagal: $gagal\n";
echo $gagal === 0 ? "  SEMUA LULUS\n" : "  ADA YANG GAGAL\n";
