<?php

/**
 * Sub-modul "Alokasi Bon Sementara" + gerbang "Lanjut Realisasikan Bon".
 *
 * Alokasi Kasbon dipindah keluar dari layar Realisasi Bon Sementara menjadi sub-modul sendiri,
 * dan bon TIDAK BOLEH sampai ke realisasi sebelum tombol "Lanjut Realisasikan Bon" ditekan.
 *
 * EMPAT HAL YANG DIJAGA, dan semuanya gagal TANPA satu pun galat kalau salah:
 *
 *  1. GERBANGNYA HARUS DI SERVICE, BUKAN DI CONTROLLER. Sebelas tempat memanggil
 *     CashAdvanceService::realize() langsung tanpa melewati controller — gerbang di controller
 *     akan dilewati semuanya, dan layar tetap terlihat menahan. Karena itu uji ini memanggil
 *     SERVICE-nya langsung, bukan rutenya.
 *  2. CAKUPANNYA HARUS SEMPIT. Hanya bon Debit VA model baru yang punya tahap alokasi. Bon
 *     REGULER dan bon kartu model LAMA tidak pernah punya Alokasi Kasbon; menggerbangi mereka
 *     akan mengunci mati seluruh bon yang alur alokasinya memang tidak ada — dan itu baru
 *     ketahuan saat ada yang mencoba merealisasi bon biasa.
 *  3. MELANJUTKAN BON TANPA ALOKASI tidak boleh membuka gerbang: Uang Muka Operasional baru
 *     terbentuk dari alokasi, jadi bonnya akan menabrak gerbang berikutnya dengan alasan yang
 *     jauh dari tempat kejadiannya.
 *  4. DAFTAR SUB-MODULNYA HARUS SEHIMPUN DENGAN GERBANGNYA. Kalau daftar memakai syarat sendiri,
 *     akan ada bon yang digerbangi tetapi tidak pernah muncul di layar tempat membukanya —
 *     terkunci tanpa jalan keluar.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-alokasi-bon-sementara.php';"
 */

use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Services\CashAdvanceAllocationService;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Exceptions\BusinessRuleException;
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
$tolak = function (callable $fn): ?string {
    try { $fn(); } catch (BusinessRuleException $e) { return $e->errorCode(); }

    return null;
};

$user = User::all()->first(fn ($u) => $u->canModule('cashbook')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);

$svc = app(CashAdvanceService::class);
$alokSvc = app(CashAdvanceAllocationService::class);
$ctl = new CashAdvanceController;

$req = function ($u, array $data = [], string $metode = 'GET') {
    $r = Request::create('/uji', $metode, $data, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $u);
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());

    return $r;
};
$props = fn ($res, $r) => json_decode((string) $res->toResponse($r)->getContent(), true);

$akunUmOp = DB::table('bs_accounts')->where('code', config('business_support.gl_accounts.advance_op'))->value('id');
$bank = DB::table('bs_accounts')->where('is_postable', 1)->where('code', 'like', '101010%')
    ->whereNotIn('id', [$akunUmOp])->orderBy('code')->limit(2)->pluck('id')->all();
$beban = DB::table('bs_accounts')->where('is_postable', 1)->where('type', 'expense')->value('id');
$cc = DB::table('bs_cost_centers')->where('is_active', 1)->value('id');
if (! $akunUmOp || count($bank) < 2 || ! $beban || ! $cc) { echo "Master belum lengkap — uji dihentikan.\n"; return; }
[$BANK_A, $BANK_B] = $bank;

$TAHUN = 2033;
$BULAN = 7;
$TGL = sprintf('%04d-%02d-10', $TAHUN, $BULAN);

DB::beginTransaction();

try {
    app(\App\Domain\BusinessSupport\Services\JournalService::class)->ensurePeriod($TAHUN, $BULAN);

    $kartu = BsCreditCard::create([
        'account_id' => $BANK_B, 'card_number' => 'UJI-LANJUT-01', 'name' => 'KARTU UJI LANJUT',
        'is_active' => true, 'balance' => 0, 'expired_date' => sprintf('%04d-12-31', $TAHUN),
        'created_by' => $user->id,
    ]);

    $buatBon = fn (string $holder, float $amount) => $svc->issue([
        'advance_date' => sprintf('%04d-%02d-01', $TAHUN, $BULAN), 'holder' => $holder,
        'purpose' => 'Uji lanjut alokasi', 'amount' => $amount, 'cost_center_id' => $cc,
        'approval_mode' => 'offline',
    ], $user->id);

    $bayarVa = fn (BsCashAdvance $b) => $svc->pay($b, [
        'paid_date' => $TGL, 'payment_method' => 'debit_va',
        'va_expired_date' => sprintf('%04d-12-31', $TAHUN), 'credit_card_id' => $kartu->id,
    ], $user->id);

    $rincian = fn (float $n) => ['realized_date' => $TGL, 'defer_settlement' => true,
        'lines' => [['expense_account_id' => $beban, 'cost_center_id' => $cc, 'description' => 'uji', 'amount' => $n]]];

    echo "\n=== 1. Bon Debit VA tidak bisa direalisasi sebelum dilanjutkan ===\n";

    $bonVa = $bayarVa($buatBon('UJI Pemegang VA', 2000000));
    $alokSvc->tambah($bonVa, ['alloc_date' => $TGL, 'description' => 'Beli ATK', 'amount' => 600000, 'cash_account_id' => $BANK_A], $user->id);
    $bonVa->refresh();

    $ok('bon ini memang melewati tahap alokasi', $svc->perluLanjutAlokasi($bonVa));
    $ok('penandanya masih kosong', $bonVa->allocation_done_at === null);

    // Dipanggil ke SERVICE, bukan ke rute: gerbang yang hanya ada di controller akan lolos di sini.
    $kode = $tolak(fn () => $svc->realize($bonVa, $rincian(500000), $user->id));
    $ok('realisasi DITOLAK sebelum dilanjutkan', $kode === 'ADV_ALLOCATION_NOT_CONFIRMED', (string) $kode);
    $ok('  dan bonnya memang belum berubah status', $bonVa->fresh()->status === 'outstanding');

    echo "\n=== 2. Tombol Lanjut Realisasikan Bon membuka gerbangnya ===\n";

    $ctl->lanjutRealisasi($req($user, [], 'POST'), $bonVa);
    $bonVa->refresh();
    $ok('penanda terisi sesudah tombol ditekan', $bonVa->allocation_done_at !== null);
    $ok('penekannya dicatat', (int) $bonVa->allocation_done_by === (int) $user->id, (string) $bonVa->allocation_done_by);

    /* Waktu aplikasi digeser satu jam sebelum klik kedua. Tanpa ini kedua klik jatuh pada
       detik yang sama, dan penimpaan menghasilkan untaian yang persis sama — ujinya hijau
       tanpa menguji apa pun. */
    $waktuPertama = (string) $bonVa->allocation_done_at;
    \Illuminate\Support\Carbon::setTestNow(now()->addHour());
    $ctl->lanjutRealisasi($req($user, [], 'POST'), $bonVa->fresh());
    $ok('menekan dua kali tidak menggeser waktunya', (string) $bonVa->fresh()->allocation_done_at === $waktuPertama,
        'tombolnya tidak idempoten — waktu lanjut jadi mengikuti klik terakhir (dapat '
        .(string) $bonVa->fresh()->allocation_done_at.', semula '.$waktuPertama.')');
    \Illuminate\Support\Carbon::setTestNow();

    $svc->realize($bonVa->fresh(), $rincian(500000), $user->id);
    $ok('realisasi BERHASIL sesudah dilanjutkan', $bonVa->fresh()->status === 'realized');

    echo "\n=== 3. Cakupan gerbang SEMPIT — bon lain tidak ikut terkunci ===\n";

    // Bon REGULER: uangnya sudah keluar dari bank saat dibayar, tidak pernah punya alokasi.
    $bonReg = $buatBon('UJI Pemegang Reguler', 1000000);
    $svc->pay($bonReg, ['paid_date' => $TGL, 'payment_method' => 'reguler', 'cash_account_id' => $BANK_A], $user->id);
    $bonReg->refresh();
    $ok('bon reguler TIDAK melewati tahap alokasi', ! $svc->perluLanjutAlokasi($bonReg));
    $ok('  penandanya kosong', $bonReg->allocation_done_at === null);
    $svc->realize($bonReg, $rincian(400000), $user->id);
    $ok('bon reguler tetap bisa direalisasi tanpa ditekan apa pun', $bonReg->fresh()->status === 'realized',
        'gerbangnya terlalu lebar — seluruh bon biasa ikut terkunci');

    // Bon kartu model LAMA: sudah terlanjur berjurnal saat dibayar (journal_id terisi).
    $bonLama = $bayarVa($buatBon('UJI Pemegang VA Lama', 900000));
    $jurnalLama = DB::table('bs_journals')->insertGetId([
        'tenant_id' => $user->tenant_id, 'journal_number' => 'UJI-JRN-LAMA-'.random_int(1000, 9999),
        'journal_date' => $TGL, 'period_year' => $TAHUN, 'period_month' => $BULAN,
        'description' => 'uji bon kartu lama', 'status' => 'posted',
        'source' => 'cash', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $bonLama->update(['journal_id' => $jurnalLama]);
    $bonLama->refresh();
    $ok('bon kartu model LAMA tidak melewati tahap alokasi', ! $svc->perluLanjutAlokasi($bonLama),
        'bon lama ikut digerbangi padahal alokasinya memang tidak pernah ada');
    /* Inilah satu-satunya bon yang membedakan definisi bersama (perluLanjutAlokasi) dari
       tebakan "payment_method === debit_va": ia debit_va TETAPI sudah berjurnal. Layar harus
       memakai definisi yang sama dengan gerbangnya — kalau tidak, bon ini terlihat terkunci
       di layar padahal servernya membiarkannya lewat. */
    $rLama = $req($user);
    $showLama = $props($ctl->show($rLama, $bonLama), $rLama);
    $ok('  dan layarnya melaporkan perlu=false untuk bon itu',
        ($showLama['props']['lanjutAlokasi']['perlu'] ?? null) === false,
        'layar memakai syaratnya sendiri: '.json_encode($showLama['props']['lanjutAlokasi'] ?? null));

    echo "\n=== 4. Melanjutkan bon tanpa alokasi ditolak ===\n";

    $bonKosong = $bayarVa($buatBon('UJI Tanpa Alokasi', 700000));
    $res = $ctl->lanjutRealisasi($req($user, [], 'POST'), $bonKosong);
    $galat = $res->getSession()?->get('errors')?->getBag('default')?->get('lanjut') ?? [];
    $ok('ditolak dengan keterangan', ! empty($galat), json_encode($galat));
    $ok('  keterangannya menyebut alokasi', ! empty($galat) && str_contains((string) $galat[0], 'Alokasi Kasbon'), json_encode($galat));
    $ok('  dan penandanya TIDAK terisi', $bonKosong->fresh()->allocation_done_at === null,
        'bon tanpa alokasi terlanjur dilanjutkan — gerbang berikutnya yang menolaknya, jauh dari sini');

    echo "\n=== 5. Daftar sub-modul sehimpun dengan gerbangnya ===\n";

    $r = $req($user);
    $daftar = $props($ctl->allocationIndex($r), $r);
    $ok('halaman daftar = Advances/Allocation/Index', ($daftar['component'] ?? '') === 'BusinessSupport/CashBook/Advances/Allocation/Index', $daftar['component'] ?? '');
    $ok('berkas .vue-nya ada', is_file(base_path('resources/js/Pages/'.($daftar['component'] ?? 'x').'.vue')));
    $ids = array_column($daftar['props']['rows']['data'] ?? [], 'id');

    $ok('bon VA yang menunggu alokasi ADA di daftar', in_array($bonKosong->id, $ids, true), json_encode($ids));
    $ok('bon reguler TIDAK ada di daftar', ! in_array($bonReg->id, $ids, true));
    $ok('bon kartu model lama TIDAK ada di daftar', ! in_array($bonLama->id, $ids, true));
    $ok('bon yang sudah direalisasi TIDAK ada di daftar', ! in_array($bonVa->id, $ids, true));

    /* Inilah jaminan "sehimpun": setiap bon yang digerbangi HARUS bisa ditemukan di layar
       tempat membuka gerbangnya. Kalau tidak, ia terkunci tanpa jalan keluar. */
    $terkunci = BsCashAdvance::query()->where('status', 'outstanding')->get()
        ->filter(fn ($b) => $svc->perluLanjutAlokasi($b) && $b->allocation_done_at === null)
        ->pluck('id')->all();
    $ok('setiap bon yang digerbangi muncul di daftarnya', count(array_diff($terkunci, $ids)) === 0,
        'terkunci tapi tak terdaftar: '.json_encode(array_values(array_diff($terkunci, $ids))));

    $barisKosong = collect($daftar['props']['rows']['data'])->firstWhere('id', $bonKosong->id);
    $ok('baris daftar menandai belum dilanjutkan', ($barisKosong['sudahLanjut'] ?? true) === false);
    $ok('  dan alokasinya nol', (float) ($barisKosong['dialokasikan'] ?? -1) === 0.0 && (int) ($barisKosong['jumlahAlokasi'] ?? -1) === 0);
    $ok('  belum dialokasikan = nominal bon', abs((float) ($barisKosong['belum'] ?? 0) - 700000) < 0.01, json_encode($barisKosong));

    echo "\n=== 6. Layar alokasi satu bon ===\n";

    $r2 = $req($user);
    $satu = $props($ctl->allocationShow($r2, $bonKosong), $r2);
    $ok('halaman = Advances/Allocation/Show', ($satu['component'] ?? '') === 'BusinessSupport/CashBook/Advances/Allocation/Show', $satu['component'] ?? '');
    $ok('berkas .vue-nya ada', is_file(base_path('resources/js/Pages/'.($satu['component'] ?? 'x').'.vue')));
    foreach (['advance', 'cashAccounts', 'allocations', 'lanjutAlokasi', 'bisaUbah'] as $p) {
        $ok("prop $p dikirim", array_key_exists($p, $satu['props'] ?? []));
    }
    $ok('keadaan gerbang dikirim apa adanya', ($satu['props']['lanjutAlokasi']['perlu'] ?? null) === true
        && ($satu['props']['lanjutAlokasi']['sudah'] ?? null) === false);

    $kode = 0;
    try { $ctl->allocationShow($req($user), $bonReg); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('bon tanpa tahap alokasi → layar alokasinya 404', $kode === 404, (string) $kode);
    $kode = 0;
    try { $ctl->lanjutRealisasi($req($user, [], 'POST'), $bonReg); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('  dan tombol lanjutnya juga 404', $kode === 404, (string) $kode);

    echo "\n=== 7. Layar Realisasi mencerminkan gerbangnya ===\n";

    $bonVa2 = $bayarVa($buatBon('UJI Cermin', 800000));
    $alokSvc->tambah($bonVa2, ['alloc_date' => $TGL, 'description' => 'uji', 'amount' => 300000, 'cash_account_id' => $BANK_A], $user->id);
    $r3 = $req($user);
    $show = $props($ctl->show($r3, $bonVa2->fresh()), $r3);
    $ok('prop lanjutAlokasi dikirim ke layar Realisasi', array_key_exists('lanjutAlokasi', $show['props'] ?? []));
    $ok('  perlu=true, sudah=false', ($show['props']['lanjutAlokasi']['perlu'] ?? null) === true
        && ($show['props']['lanjutAlokasi']['sudah'] ?? null) === false, json_encode($show['props']['lanjutAlokasi'] ?? null));

    echo "\n=== 8. Gerbang izin ===\n";

    $polos = new User(['tenant_id' => $user->tenant_id, 'name' => 'UJI Alokasi Tanpa Izin',
        'email' => 'uji-alok-'.random_int(100000, 999999).'@contoh.test', 'password' => bcrypt('rahasia')]);
    $polos->save();
    foreach ([
        'daftar' => fn () => $ctl->allocationIndex($req($polos)),
        'layar'  => fn () => $ctl->allocationShow($req($polos), $bonKosong),
        'lanjut' => fn () => $ctl->lanjutRealisasi($req($polos, [], 'POST'), $bonKosong),
    ] as $aksi => $jalan) {
        $kode = 0;
        try { $jalan(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
        $ok("tanpa izin: $aksi ditolak", $kode === 403, (string) $kode);
    }

    /* MELIHAT ≠ MELANJUTKAN. Tanpa user yang punya view TAPI TIDAK punya realize, menurunkan
       gerbang tombol lanjut dari realize ke view tidak akan ketahuan sama sekali. */
    $pembaca = new User(['tenant_id' => $user->tenant_id, 'name' => 'UJI Alokasi Hanya Lihat',
        'email' => 'uji-alok-lihat-'.random_int(100000, 999999).'@contoh.test', 'password' => bcrypt('rahasia')]);
    $pembaca->save();
    $pembaca->givePermissionTo('cashbook.advance.view');
    $pembaca = $pembaca->fresh();
    $ok('user hanya-lihat terbentuk', $pembaca->can('cashbook.advance.view') && ! $pembaca->can('cashbook.advance.realize'));

    $boleh = true;
    try { $ctl->allocationIndex($req($pembaca)); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $boleh = false; }
    $ok('hanya-lihat BOLEH membuka daftarnya', $boleh);
    $rp = $req($pembaca);
    $ok('  tetapi layarnya tahu ia tidak boleh mengubah',
        ($props($ctl->allocationShow($rp, $bonKosong), $rp)['props']['bisaUbah'] ?? true) === false);
    $kode = 0;
    try { $ctl->lanjutRealisasi($req($pembaca, [], 'POST'), $bonKosong); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('  dan DITOLAK menekan Lanjut Realisasikan Bon', $kode === 403, (string) $kode);

    echo "\n=== 9. Rute ===\n";

    foreach ([
        'bs.advance.allocation.index'  => 'allocationIndex',
        'bs.advance.allocation.show'   => 'allocationShow',
        'bs.advance.allocation.lanjut' => 'lanjutRealisasi',
    ] as $nama => $aksi) {
        $rute = \Illuminate\Support\Facades\Route::getRoutes()->getByName($nama);
        $ok("rute $nama → $aksi", $rute !== null && str_contains((string) $rute->getActionName(), 'CashAdvanceController@'.$aksi),
            (string) $rute?->getActionName());
    }
    $ok('kolom penanda ada di basis data', \Illuminate\Support\Facades\Schema::hasColumn('bs_cash_advances', 'allocation_done_at'));
    $ok('kolomnya fillable (semua penulisan bon memakai update massal)',
        in_array('allocation_done_at', (new BsCashAdvance)->getFillable(), true));

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
} catch (\Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
}
