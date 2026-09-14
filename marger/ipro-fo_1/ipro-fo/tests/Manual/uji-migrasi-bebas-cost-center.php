<?php

/**
 * JURNAL MIGRASI DATA AWAL — dibebaskan dari kewajiban cost center.
 *
 * Cost center wajib karena ia yang membuat sebuah baris terbaca sebagai realisasi anggaran, dan
 * sejak itu menjadi syarat Final → Approved. Tetapi jurnal MIGRASI (Import Jurnal & Import Saldo
 * Awal) tidak pernah menjadi realisasi anggaran siapa pun: ia memindahkan saldo yang SUDAH terjadi
 * di sistem lama. Memaksakan cost center di sana berarti mengarang departemen penanggung untuk
 * angka yang bahkan bukan dari periode berjalan — dan sampai dikarang, seluruh jurnalnya tertahan
 * di Final sehingga saldo awalnya tidak pernah efektif di buku besar.
 *
 * APA YANG DIJAGA:
 *   1. Jurnal bersumber 'import' dan 'saldo_awal' bisa di-approve TANPA cost center.
 *   2. Jurnal sumber LAIN tetap ditahan — pembebasan ini tidak boleh bocor ke jurnal biasa.
 *   3. Detail Transaksi Jurnal menandainya "Migrasi Data Awal", bukan "n akun belum bercost center".
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-migrasi-bebas-cost-center.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\BusinessSupport\Gl\JournalController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
$tolak = function (callable $fn): ?string {
    try { $fn(); } catch (BusinessRuleException $e) { return $e->errorCode(); }

    return null;
};

$user = User::all()->first(fn ($u) => $u->canModule('businesssupport')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

$svc = app(JournalService::class);

/* Akun yang cost center-nya WAJIB — kalau akunnya kebetulan bertanda optional, seluruh uji ini
   akan lulus tanpa membuktikan apa pun. */
$beban = BsAccount::where('is_postable', true)->where('type', 'expense')
    ->where(fn ($q) => $q->where('cost_center_optional', false)->orWhereNull('cost_center_optional'))
    ->orderBy('code')->first();
$kas = BsAccount::where('is_postable', true)->where('type', 'asset')
    ->where(fn ($q) => $q->where('cost_center_optional', false)->orWhereNull('cost_center_optional'))
    ->orderBy('code')->first();
if (! $beban || ! $kas) { echo "Akun beban/kas yang wajib cost center tidak ada — uji dihentikan.\n"; return; }

$TAHUN = 2041;
$TGL   = sprintf('%04d-03-05', $TAHUN);

DB::beginTransaction();

try {
    $svc->ensurePeriod($TAHUN, 3);

    /** Jurnal dua baris TANPA cost center sama sekali. */
    $buat = fn (string $source, string $ref) => $svc->post([
        'journal_date' => $TGL, 'source' => $source, 'status' => 'final',
        'reference' => $ref, 'description' => 'Uji bebas cost center '.$source, 'created_by' => $user->id,
    ], [
        ['account_id' => $beban->id, 'debit' => 500000, 'credit' => 0, 'description' => 'Beban tanpa cc'],
        ['account_id' => $kas->id,   'debit' => 0, 'credit' => 500000, 'description' => 'Kas tanpa cc'],
    ]);

    echo "\n=== 1. AKUN UJINYA MEMANG WAJIB BERCOST CENTER ===\n";

    $biasa = $buat('memorial', 'UJI-CC-MEMORIAL');
    $ok('jurnal memorial tanpa cost center terdeteksi kurang',
        count($svc->barisTanpaCostCenter($biasa)) === 2, json_encode($svc->barisTanpaCostCenter($biasa)));
    $kode = $tolak(fn () => $svc->approve($biasa, $user->id));
    $ok('dan memang tertahan saat di-approve', $kode !== null, 'justru lolos — akun ujinya mungkin bertanda optional');

    echo "\n=== 2. IMPORT JURNAL DIBEBASKAN ===\n";

    $imp = $buat('import', 'UJI-CC-IMPORT');
    $ok('tidak ada baris yang dianggap kurang', $svc->barisTanpaCostCenter($imp) === [], json_encode($svc->barisTanpaCostCenter($imp)));
    $ok('penanda migrasiDataAwal() menyala', $imp->migrasiDataAwal());

    $svc->approve($imp->refresh(), $user->id);
    $ok('bisa di-approve tanpa cost center', $imp->refresh()->status === 'posted', (string) $imp->status);

    echo "\n=== 3. IMPORT SALDO AWAL JUGA DIBEBASKAN ===\n";

    $sa = $buat('saldo_awal', 'UJI-CC-SALDOAWAL');
    $ok('tidak ada baris yang dianggap kurang', $svc->barisTanpaCostCenter($sa) === [], json_encode($svc->barisTanpaCostCenter($sa)));
    $svc->approve($sa->refresh(), $user->id);
    $ok('bisa di-approve tanpa cost center', $sa->refresh()->status === 'posted', (string) $sa->status);

    echo "\n=== 4. PEMBEBASAN TIDAK BOCOR KE SUMBER LAIN ===\n";

    /* Kalau pembebasannya ditulis terlalu longgar, jurnal kas/AP/AR ikut lolos — dan justru
       jurnal itulah yang paling sering menjadi realisasi anggaran. */
    foreach (['cash', 'ap', 'ar'] as $i => $src) {
        $j = $buat($src, 'UJI-CC-'.strtoupper($src));
        $ok("sumber '{$src}' tetap wajib bercost center",
            count($svc->barisTanpaCostCenter($j)) === 2, json_encode($svc->barisTanpaCostCenter($j)));
        $ok("sumber '{$src}' tetap tertahan saat di-approve", $tolak(fn () => $svc->approve($j, $user->id)) !== null);
    }

    echo "\n=== 5. DETAIL TRANSAKSI JURNAL MENANDAINYA ===\n";

    // Namanya date_from/date_to — 'from'/'to' diabaikan diam-diam dan layarnya jatuh ke bulan ini.
    $req = Request::create('/x', 'GET', [
        'date_from' => sprintf('%04d-03-01', $TAHUN), 'date_to' => sprintf('%04d-03-31', $TAHUN),
    ], [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $req->setUserResolver(fn () => $user);
    app()->instance('request', $req);
    $props = app(JournalController::class)->index($req)->toResponse($req)->getData(true)['props'];

    $cari = fn (string $ref) => collect($props['journals']['data'] ?? [])->firstWhere('reference', $ref);

    $bImp = $cari('UJI-CC-IMPORT');
    $ok('baris Import Jurnal ada di layar', $bImp !== null);
    $ok('ditandai cost_center_migrasi', $bImp && ($bImp['cost_center_migrasi'] ?? false) === true, json_encode($bImp['cost_center_migrasi'] ?? null));
    $ok('TIDAK lagi dilaporkan kurang cost center',
        $bImp && ($bImp['cost_center_kurang'] ?? []) === [], json_encode($bImp['cost_center_kurang'] ?? null));

    $bSa = $cari('UJI-CC-SALDOAWAL');
    $ok('Import Saldo Awal juga ditandai', $bSa && ($bSa['cost_center_migrasi'] ?? false) === true, json_encode($bSa['cost_center_migrasi'] ?? null));

    $bMem = $cari('UJI-CC-MEMORIAL');
    $ok('jurnal memorial TIDAK ditandai migrasi', $bMem && ($bMem['cost_center_migrasi'] ?? false) === false, json_encode($bMem['cost_center_migrasi'] ?? null));
    $ok('dan tetap dilaporkan kurang cost center',
        $bMem && count($bMem['cost_center_kurang'] ?? []) === 2, json_encode($bMem['cost_center_kurang'] ?? null));

    echo "\n──────────────────────────────────────────────────────────────────────────────\n";
    echo $gagal === 0 ? "SEMUA LULUS ($lulus)\n" : "ADA YANG GAGAL — lulus $lulus, gagal $gagal\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}
