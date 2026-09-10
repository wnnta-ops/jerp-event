<?php

/**
 * Set Up Debit Card — SATU AKUN GL boleh dipakai BEBERAPA kartu debit/VA.
 *
 * KENAPA DULU DIBATASI SATU. Angka kartu dulu dibandingkan dengan MUTASI AKUNNYA di buku besar:
 * pembayaran Debit VA mendebit akun kartu, realisasinya mengkredit akun itu, dan "Pemakaian"
 * dibaca dari SUM(credit) akun tersebut. Dengan dua kartu berakun sama, satu angka di buku besar
 * tidak bisa dibagi ke kartu mana — jadi akunnya dikunci satu kartu (unique tenant_id+account_id
 * di skema, plus Rule::unique di controller).
 *
 * KENAPA SEKARANG AMAN. Sejak pembayaran Debit VA tidak berjurnal, akun kartu tidak bermutasi
 * sama sekali; keempat angka kartu dihitung PER KARTU dari dokumen yang menunjuk credit_card_id
 * (pembayaran bon & baris Alokasi Kasbon). Itulah yang dijaga tahap 4 di bawah: dua kartu
 * berbagi satu akun harus tetap punya angka sendiri-sendiri, tidak tercampur.
 *
 * Penggantinya NOMOR KARTU: dua kartu boleh berbagi rekening, tetapi tidak boleh bernomor sama —
 * nomor itulah satu-satunya yang membedakan keduanya di daftar maupun di pemilih kartu pada
 * pembayaran bon.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-kartu-satu-akun-banyak.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Services\CashAdvanceAllocationService;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Domain\BusinessSupport\Services\CreditCardClosingService;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CashAdvanceController;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CreditCardController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => $u->canModule('cashbook')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

$ctl = app(CreditCardController::class);
$svcAdv = app(CashAdvanceService::class);
$alokSvc = app(CashAdvanceAllocationService::class);
$closingSvc = app(CreditCardClosingService::class);

$post = function (array $data) use ($user) {
    $r = Request::create('/business-support/credit-cards', 'POST', $data);
    $r->setUserResolver(fn () => $user);

    return $r;
};
$reqInertia = function (array $query = []) use ($user) {
    $r = Request::create('/x', 'GET', $query, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);

    return $r;
};
/** Jalankan store(); kembalikan galat validasinya (kosong bila diterima). */
$tambah = function (array $data) use ($ctl, $post): array {
    try {
        $ctl->store($post($data));

        return [];
    } catch (ValidationException $e) {
        return $e->errors();
    }
};

$akunKartu = BsAccount::where('is_active', true)->where('is_postable', true)
    ->where('code', 'like', '101010202%')->orderBy('code')->first(['id', 'code', 'name']);
$akunLain = BsAccount::where('is_active', true)->where('is_postable', true)
    ->where('code', 'like', '101010202%')->where('id', '!=', $akunKartu?->id)->orderBy('code')->first(['id', 'code']);
$bank = BsAccount::where('is_postable', true)->where('code', 'like', '101010%')
    ->whereNotIn('id', array_filter([$akunKartu?->id, $akunLain?->id]))->orderBy('code')->first(['id']);
$cc = DB::table('bs_cost_centers')->where('is_active', 1)->value('id');
$beban = BsAccount::where('is_postable', true)->where('type', 'expense')->first(['id']);

echo "\n=== PRASYARAT ===\n";
$ok('ada dua akun bank yang bisa dijadikan kartu', $akunKartu && $akunLain);
$ok('ada bank pengeluaran, cost center & akun beban', $bank && $cc && $beban);
if (! $akunKartu || ! $akunLain || ! $bank || ! $cc || ! $beban) { echo "\nPrasyarat kurang.\n"; return; }

DB::beginTransaction();

try {
    echo "\n=== 1. SKEMANYA TIDAK LAGI MENGUNCI SATU KARTU PER AKUN ===\n";

    $indeksUnik = collect(DB::select('SHOW INDEX FROM `bs_credit_cards`'))
        ->filter(fn ($r) => (int) ((array) $r)['Non_unique'] === 0 && ((array) $r)['Column_name'] === 'account_id');
    $ok('tidak ada lagi indeks UNIK pada account_id', $indeksUnik->isEmpty(),
        $indeksUnik->pluck('Key_name')->unique()->join(', '));
    $adaIndeksBiasa = collect(DB::select('SHOW INDEX FROM `bs_credit_cards`'))
        ->contains(fn ($r) => ((array) $r)['Column_name'] === 'account_id');
    $ok('account_id tetap terindeks (pencariannya tidak menjadi lambat)', $adaIndeksBiasa);

    echo "\n=== 2. DUA KARTU PADA SATU AKUN GL ===\n";

    $galat = $tambah(['account_id' => $akunKartu->id, 'card_number' => 'UJI-A-001', 'name' => 'KARTU UJI A']);
    $ok('kartu pertama diterima', $galat === [], json_encode($galat));

    $galat = $tambah(['account_id' => $akunKartu->id, 'card_number' => 'UJI-A-002', 'name' => 'KARTU UJI A KEDUA']);
    $ok('kartu KEDUA pada akun yang SAMA diterima', $galat === [],
        json_encode($galat).' — inilah yang dulu ditolak dengan "account id sudah dipakai"');

    $sekartu = BsCreditCard::where('account_id', $akunKartu->id)->whereIn('card_number', ['UJI-A-001', 'UJI-A-002'])->get();
    $ok('keduanya benar-benar tersimpan', $sekartu->count() === 2, (string) $sekartu->count());
    $ok('keduanya menunjuk akun GL yang sama',
        $sekartu->pluck('account_id')->unique()->count() === 1);
    $ok('keduanya punya id sendiri', $sekartu->pluck('id')->unique()->count() === 2);
    $ok('label keduanya berbeda — bisa dibedakan di dropdown',
        $sekartu->map(fn ($c) => $c->label())->unique()->count() === 2,
        $sekartu->map(fn ($c) => $c->label())->join(' | '));

    $kartuA = $sekartu->firstWhere('card_number', 'UJI-A-001');
    $kartuB = $sekartu->firstWhere('card_number', 'UJI-A-002');

    echo "\n=== 3. NOMOR KARTU YANG MENGGANTIKAN PEMBEDANYA ===\n";

    $galat = $tambah(['account_id' => $akunKartu->id, 'card_number' => 'UJI-A-001', 'name' => 'KARTU KEMBAR']);
    $ok('nomor kartu yang sama DITOLAK', isset($galat['card_number']), json_encode($galat));
    $ok('pesannya menyebut nomornya harus berbeda',
        str_contains(json_encode($galat), 'sudah terdaftar'), json_encode($galat));

    // ...termasuk bila akunnya berbeda: nomor kartu adalah identitas kartunya sendiri.
    $galat = $tambah(['account_id' => $akunLain->id, 'card_number' => 'UJI-A-001', 'name' => 'KARTU AKUN LAIN']);
    $ok('nomor sama pada akun BERBEDA pun ditolak', isset($galat['card_number']), json_encode($galat));

    $galat = $tambah(['account_id' => $akunKartu->id, 'card_number' => '', 'name' => 'KARTU TANPA NOMOR']);
    $ok('kartu baru tanpa nomor DITOLAK', isset($galat['card_number']), json_encode($galat));
    $ok('pesannya menjelaskan mengapa nomornya wajib',
        str_contains(json_encode($galat), 'membedakannya'), json_encode($galat));

    // Akun lain dengan nomor lain tetap boleh — pembatasannya nomor, bukan akun.
    $galat = $tambah(['account_id' => $akunLain->id, 'card_number' => 'UJI-B-001', 'name' => 'KARTU UJI B']);
    $ok('akun lain dengan nomor lain tetap diterima', $galat === [], json_encode($galat));

    echo "\n=== 4. ANGKA KEDUA KARTU TIDAK TERCAMPUR — INI ALASAN BERBAGI AKUN AMAN ===\n";

    $TAHUN = 2034;
    $BULAN = 3;
    $TGL = sprintf('%04d-%02d-12', $TAHUN, $BULAN);
    app(JournalService::class)->ensurePeriod($TAHUN, $BULAN);

    foreach ([$kartuA, $kartuB] as $k) {
        $k->update(['is_active' => true, 'expired_date' => sprintf('%04d-12-31', $TAHUN)]);
    }

    /** Bon Debit VA + alokasinya pada satu kartu. */
    $bonKartu = function (BsCreditCard $kartu, float $nominal, float $alokasi) use ($svcAdv, $alokSvc, $user, $cc, $TAHUN, $BULAN, $TGL, $bank) {
        $bon = $svcAdv->issue([
            'advance_date' => sprintf('%04d-%02d-01', $TAHUN, $BULAN), 'holder' => 'UJI '.$kartu->card_number,
            'purpose' => 'uji berbagi akun', 'amount' => $nominal,
            'cost_center_id' => $cc, 'approval_mode' => 'offline',
        ], $user->id);
        $svcAdv->pay($bon, ['paid_date' => $TGL, 'payment_method' => 'debit_va',
            'va_expired_date' => sprintf('%04d-12-31', $TAHUN), 'credit_card_id' => $kartu->id], $user->id);
        $bon->refresh();
        $alokSvc->tambah($bon, ['alloc_date' => $TGL, 'description' => 'uji', 'amount' => $alokasi, 'cash_account_id' => $bank->id], $user->id);

        return $bon->refresh();
    };

    $bonA = $bonKartu($kartuA, 5000000, 2000000);
    $bonB = $bonKartu($kartuB, 3000000, 1000000);

    $rA = $closingSvc->ringkasan((int) $kartuA->id, $TAHUN, $BULAN);
    $rB = $closingSvc->ringkasan((int) $kartuB->id, $TAHUN, $BULAN);

    $ok('Penambahan kartu A hanya dari bonnya sendiri',
        abs($rA['penambahan'] - 5000000) < 0.01, (string) $rA['penambahan'].' (kalau 8.000.000 berarti tercampur)');
    $ok('Penambahan kartu B hanya dari bonnya sendiri',
        abs($rB['penambahan'] - 3000000) < 0.01, (string) $rB['penambahan']);
    $ok('Pemakaian kartu A hanya dari alokasinya sendiri',
        abs($rA['pemakaian'] - 2000000) < 0.01, (string) $rA['pemakaian'].' (kalau 3.000.000 berarti tercampur)');
    $ok('Pemakaian kartu B hanya dari alokasinya sendiri',
        abs($rB['pemakaian'] - 1000000) < 0.01, (string) $rB['pemakaian']);
    $ok('Sisa Saldo keduanya berdiri sendiri',
        abs($rA['sisa_saldo'] - 3000000) < 0.01 && abs($rB['sisa_saldo'] - 2000000) < 0.01,
        $rA['sisa_saldo'].' / '.$rB['sisa_saldo']);

    echo "\n=== 5. CLOSING PUN BERDIRI SENDIRI PER KARTU ===\n";

    $closingSvc->close($kartuA, $TAHUN, $BULAN, $user->id);
    $rA2 = $closingSvc->ringkasan((int) $kartuA->id, $TAHUN, $BULAN);
    $rB2 = $closingSvc->ringkasan((int) $kartuB->id, $TAHUN, $BULAN);
    $ok('kartu A tercatat sudah closing', $rA2['closed'] === true);
    $ok('kartu B pada akun yang sama TIDAK ikut tertutup', $rB2['closed'] === false,
        'closing satu kartu tidak boleh menutup kartu lain yang berbagi akun');

    echo "\n=== 6. LAYAR MENAMPILKAN KEDUANYA ===\n";

    $props = $ctl->index($reqInertia(['year' => $TAHUN, 'month' => $BULAN]), $closingSvc)
        ->toResponse($reqInertia())->getData(true)['props'];
    $barisA = collect($props['cards'])->firstWhere('id', $kartuA->id);
    $barisB = collect($props['cards'])->firstWhere('id', $kartuB->id);
    $ok('kedua kartu tampil di daftar', $barisA !== null && $barisB !== null);
    $ok('keduanya menampilkan akun GL yang sama',
        ($barisA['account_code'] ?? null) === ($barisB['account_code'] ?? null)
        && ($barisA['account_code'] ?? null) === $akunKartu->code,
        ($barisA['account_code'] ?? '?').' / '.($barisB['account_code'] ?? '?'));
    $ok('angka layar kartu A = angka service-nya', abs((float) $barisA['pemakaian'] - $rA2['pemakaian']) < 0.01);
    $ok('angka layar kartu B = angka service-nya', abs((float) $barisB['pemakaian'] - $rB2['pemakaian']) < 0.01);

    /* DIPERIKSA DENGAN ANGKA MUTLAK, bukan dibandingkan dengan pemanggilan service yang sama.
       Layar menghitung SELURUH kartu sekaligus (ringkasanBanyak), sedangkan tahap 4 memeriksa
       satu per satu (ringkasan) — dan justru pada jalur "sekaligus" itulah dua kartu yang
       berbagi akun paling mungkin saling tertukar angkanya. Membandingkannya dengan service
       lagi berarti kedua sisi salah bersama-sama tanpa ketahuan. */
    $ok('Penambahan kartu A di layar tetap 5.000.000',
        abs((float) $barisA['penambahan'] - 5000000) < 0.01,
        (string) $barisA['penambahan'].' (kalau 8.000.000 berarti kedua kartu ditumpuk)');
    $ok('Penambahan kartu B di layar tetap 3.000.000',
        abs((float) $barisB['penambahan'] - 3000000) < 0.01, (string) $barisB['penambahan']);
    $ok('Pemakaian kartu A di layar tetap 2.000.000',
        abs((float) $barisA['pemakaian'] - 2000000) < 0.01, (string) $barisA['pemakaian']);
    $ok('Pemakaian kartu B di layar tetap 1.000.000',
        abs((float) $barisB['pemakaian'] - 1000000) < 0.01, (string) $barisB['pemakaian']);

    // Pemilih kartu pada layar pembayaran bon harus memuat keduanya, dengan label berbeda.
    $bonBaru = $svcAdv->issue([
        'advance_date' => $TGL, 'holder' => 'UJI Pemilih', 'purpose' => 'uji', 'amount' => 100000,
        'cost_center_id' => $cc, 'approval_mode' => 'offline',
    ], $user->id);
    $propsBon = app(CashAdvanceController::class)->show($reqInertia(), $bonBaru)
        ->toResponse($reqInertia())->getData(true)['props'];
    $pilihan = collect($propsBon['creditCards']);
    $ok('kedua kartu muncul sebagai pilihan pembayaran Debit VA',
        $pilihan->contains('id', $kartuA->id) && $pilihan->contains('id', $kartuB->id),
        $pilihan->pluck('label')->join(' | '));
    $ok('label pilihannya berbeda satu sama lain',
        $pilihan->pluck('label')->unique()->count() === $pilihan->count(),
        $pilihan->pluck('label')->join(' | '));

    echo "\n=== 7. UBAH KARTU: NOMORNYA SENDIRI BOLEH DIPERTAHANKAN ===\n";

    $ubah = function (BsCreditCard $kartu, array $data) use ($ctl, $post): array {
        try {
            $ctl->update($post($data), $kartu);

            return [];
        } catch (ValidationException $e) {
            return $e->errors();
        }
    };

    $galat = $ubah($kartuA, ['card_number' => 'UJI-A-001', 'name' => 'KARTU UJI A DIUBAH', 'is_active' => true]);
    $ok('menyimpan ulang dengan nomornya sendiri diterima', $galat === [], json_encode($galat));
    $ok('namanya benar-benar berubah', $kartuA->refresh()->name === 'KARTU UJI A DIUBAH', (string) $kartuA->name);

    $galat = $ubah($kartuA, ['card_number' => 'UJI-A-002', 'name' => 'KARTU UJI A', 'is_active' => true]);
    $ok('memakai nomor kartu LAIN ditolak', isset($galat['card_number']), json_encode($galat));
    $ok('nomor kartunya tidak ikut berubah oleh penolakan itu',
        $kartuA->refresh()->card_number === 'UJI-A-001', (string) $kartuA->card_number);

    echo "\n=== 8. NOMOR KARTU YANG SUDAH DIHAPUS BOLEH DIPAKAI LAGI ===\n";

    $kartuB->delete();   // soft delete
    $galat = $tambah(['account_id' => $akunKartu->id, 'card_number' => 'UJI-A-002', 'name' => 'KARTU PENGGANTI']);
    $ok('nomor milik kartu yang sudah dihapus bisa dipakai kembali', $galat === [], json_encode($galat));
    $ok('kartu penggantinya tercatat pada akun yang sama',
        BsCreditCard::where('card_number', 'UJI-A-002')->where('account_id', $akunKartu->id)->exists());
} finally {
    DB::rollBack();
}

echo "\n────────────────────────────────\n";
echo "LULUS: $lulus   GAGAL: $gagal\n";
echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
