<?php

/**
 * Detail Transaksi Jurnal — kolom Baris dihapus, Anggaran diganti Cost Center, dan
 * Final → Approved MEWAJIBKAN cost center lengkap.
 *
 * KENAPA GERBANGNYA DI Final → Approved, BUKAN DI FINALISASI. Approved berarti jurnalnya
 * efektif di buku besar, dan sejak saat itu angkanya ikut terbaca sebagai realisasi. Baris
 * tanpa cost center tetap menjadi beban di GL tetapi TIDAK PERNAH muncul sebagai realisasi di
 * Budget Control — anggarannya seolah tidak pernah terpakai. Itu tidak menimbulkan galat apa
 * pun: hanya angka yang kurang, dan biasanya baru ketahuan berbulan kemudian.
 *
 * PENGECUALIANNYA DIPERTAHANKAN: akun bertanda cost_center_optional (CoaTanpaCostCenterSeeder)
 * memang tidak menanggung cost center. Mewajibkannya untuk SEMUA baris akan mengunci approval
 * pada jurnal yang justru paling biasa: Dr beban (ber-cost center) / Cr Utang Usaha (tanpa).
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-jurnal-cost-center-approve.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\BusinessSupport\Gl\JournalController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
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

$user = User::all()->first(fn ($u) => (bool) $u->canModule('gl')) ?? User::first();
auth()->setUser($user);
$svc = app(JournalService::class);

$akunWajib = BsAccount::where('is_postable', true)->where('type', 'expense')
    ->where(fn ($q) => $q->where('cost_center_optional', false)->orWhereNull('cost_center_optional'))
    ->first(['id', 'code', 'name', 'cost_center_optional']);
$akunOptional = BsAccount::where('is_postable', true)->where('cost_center_optional', true)
    ->first(['id', 'code', 'name', 'cost_center_optional']);
$cc1 = BsCostCenter::where('is_active', true)->orderBy('code')->first(['id', 'code', 'name']);
$cc2 = BsCostCenter::where('is_active', true)->where('id', '!=', $cc1?->id)->orderBy('code')->first(['id', 'code', 'name']);

echo "\n=== PRASYARAT ===\n";
$ok('ada akun WAJIB cost center', (bool) $akunWajib, (string) $akunWajib?->code);
$ok('ada akun BOLEH tanpa cost center', (bool) $akunOptional, (string) $akunOptional?->code);
$ok('ada dua cost center untuk diuji', $cc1 && $cc2, ($cc1?->code ?? '?').' & '.($cc2?->code ?? '?'));
if (! $akunWajib || ! $akunOptional || ! $cc1 || ! $cc2) {
    echo "\nPrasyarat kurang — uji dihentikan.\n"; return;
}

DB::beginTransaction();
try {

/** Jurnal berstatus FINAL, dua baris seimbang, dengan cost center sesuai pilihan. */
$buatFinal = function (?int $headerCc, ?int $ccBaris1, ?int $ccBaris2, ?int $akun2 = null) use ($svc, $user, $akunWajib, $akunOptional) {
    $j = $svc->post([
        'journal_date' => now()->toDateString(),
        'source'       => 'memorial',
        'description'  => 'UJI cost center approve',
        'created_by'   => $user?->id,
        'cost_center_id' => $headerCc,
    ], [
        ['account_id' => $akunWajib->id, 'cost_center_id' => $ccBaris1, 'debit' => 100000, 'credit' => 0, 'description' => 'uji debit'],
        ['account_id' => $akun2 ?: $akunOptional->id, 'cost_center_id' => $ccBaris2, 'debit' => 0, 'credit' => 100000, 'description' => 'uji kredit'],
    ]);
    // post() melahirkan jurnal Baru; difinalisasi dulu supaya berdiri di gerbang yang diuji.
    if ($j->status === 'draft') {
        $svc->finalize($j);
    }

    return $j->refresh();
};

echo "\n=== 1. FINALISASI TIDAK IKUT DIWAJIBKAN ===\n";
// Gerbangnya sengaja HANYA di Final → Approved. Menaruhnya di finalisasi akan menahan jurnal
// yang belum tentu sudah selesai disunting, dan itu bukan yang diminta.
$tanpaCc = $buatFinal(null, null, null);
$ok('jurnal tanpa cost center tetap bisa difinalisasi', $tanpaCc->status === 'final', (string) $tanpaCc->status);

echo "\n=== 2. FINAL → APPROVED DITAHAN BILA COST CENTER KURANG ===\n";
$tolak('approve ditolak saat cost center kosong', 'JOURNAL_NO_COST_CENTER',
    fn () => $svc->approve($tanpaCc, $user?->id));
$ok('statusnya tetap Final (tidak berpindah)', $tanpaCc->refresh()->status === 'final', (string) $tanpaCc->status);
$ok('posted_at tetap kosong', $tanpaCc->posted_at === null);

// Pesannya harus MENYEBUT akun mana yang kurang — tanpa itu, pada jurnal berisi sepuluh baris
// orang hanya melihat penolakan dan harus menebak baris mana yang menahannya.
$pesan = '';
try { $svc->approve($tanpaCc, $user?->id); } catch (BusinessRuleException $e) { $pesan = $e->getMessage(); }
$ok('pesannya menyebut kode akun yang kurang', str_contains($pesan, (string) $akunWajib->code), $pesan);
$ok('pesannya menyebut nomor jurnalnya', str_contains($pesan, (string) $tanpaCc->journal_number));

echo "\n=== 3. AKUN BOLEH-TANPA-COST-CENTER TIDAK DIHITUNG KURANG ===\n";
// Baris 2 memakai akun optional dan sengaja dibiarkan kosong; hanya baris 1 yang dilengkapi.
$sebagian = $buatFinal(null, $cc1->id, null);
$kurang = $svc->barisTanpaCostCenter($sebagian);
$ok('tidak ada akun yang dianggap kurang', $kurang === [], json_encode($kurang));
$svc->approve($sebagian, $user?->id);
$ok('jurnalnya berhasil Approved', $sebagian->refresh()->status === 'posted', (string) $sebagian->status);

echo "\n=== 4. BARIS KOSONG MEWARISI COST CENTER HEADER ===\n";
// Aturan warisan harus SAMA dengan saat jurnalnya disimpan. Kalau gerbang ini memakai aturan
// sendiri, ia akan menolak jurnal yang sebenarnya sudah lengkap di GL.
$warisHeader = $buatFinal($cc2->id, null, null, $akunWajib->id);
$ok('tidak ada yang kurang karena headernya terisi', $svc->barisTanpaCostCenter($warisHeader) === [],
    json_encode($svc->barisTanpaCostCenter($warisHeader)));
$svc->approve($warisHeader, $user?->id);
$ok('jurnal berbekal cost center header bisa Approved', $warisHeader->refresh()->status === 'posted');

echo "\n=== 5. AKUN WAJIB YANG KOSONG TETAP DITAHAN WALAU BARIS LAIN TERISI ===\n";
$campur = $buatFinal(null, $cc1->id, null, $akunWajib->id);   // baris 2 akun wajib, cc kosong
$kurangCampur = $svc->barisTanpaCostCenter($campur);
$ok('akun wajib yang kosong terdeteksi', count($kurangCampur) === 1, json_encode($kurangCampur));
$tolak('approve tetap ditolak', 'JOURNAL_NO_COST_CENTER', fn () => $svc->approve($campur, $user?->id));
$ok('statusnya tetap Final', $campur->refresh()->status === 'final');

echo "\n=== 6. SETELAH DILENGKAPI, APPROVE BERHASIL ===\n";
$campur->lines()->whereNull('cost_center_id')->update(['cost_center_id' => $cc2->id]);
$ok('sesudah dilengkapi tidak ada yang kurang', $svc->barisTanpaCostCenter($campur->refresh()) === []);
$svc->approve($campur, $user?->id);
$ok('jurnalnya berpindah ke Approved', $campur->refresh()->status === 'posted', (string) $campur->status);
$ok('approved_by tercatat', $campur->approved_by !== null);

echo "\n=== 7. APPROVE MASSAL MEMAKAI GERBANG YANG SAMA ===\n";
$massal = $buatFinal(null, null, null, $akunWajib->id);
$ctl = app(JournalController::class);
$req = \Illuminate\Http\Request::create('/uji/approve-batch', 'POST', ['ids' => [$massal->id]]);
$req->setUserResolver(fn () => $user);
$req->setLaravelSession(app('session.store'));
app('session.store')->flush();
try { $ctl->approveBatch($req, $svc); } catch (\Throwable $e) { /* ditangkap batch */ }
$ok('approve massal TIDAK memindahkan jurnal yang kurang cost center',
    $massal->refresh()->status === 'final', (string) $massal->status);

echo "\n=== 8. LAYAR: KOLOM BARIS HILANG, ANGGARAN JADI COST CENTER ===\n";
$rq = \Illuminate\Http\Request::create('/business-support/gl/journals/detail', 'GET',
    ['date_from' => now()->startOfYear()->toDateString(), 'date_to' => now()->endOfYear()->toDateString()]);
$rq->setUserResolver(fn () => $user);
$rq->headers->set('X-Inertia', 'true');
$rq->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
$props = json_decode((string) $ctl->index($rq)
    ->toResponse($rq)->getContent(), true)['props'] ?? [];

$ok('layar tidak lagi menerima budgetStatuses', ! array_key_exists('budgetStatuses', $props));
$ok('opsi saringan Cost Center dikirim', array_key_exists('cost_center', $props['opsiKolom'] ?? []));
$ok('opsi saringan Baris sudah tidak ada', ! array_key_exists('lines', $props['opsiKolom'] ?? []));
$ok('saringan f_lines & f_budget tidak lagi diterima',
    ! array_key_exists('f_lines', $props['filters'] ?? []) && ! array_key_exists('f_budget', $props['filters'] ?? []),
    json_encode(array_keys($props['filters'] ?? [])));

// Baris jurnal uji harus membawa cost center efektifnya.
$cari = fn (string $no) => collect($props['journals']['data'] ?? [])->firstWhere('journal_number', $no);
$bWaris = $cari($warisHeader->journal_number);
$bCampur = $cari($campur->journal_number);
$bKurang = $cari($tanpaCc->journal_number);
$ok('jurnal ber-cost-center header menampilkan cost center itu',
    $bWaris && in_array($cc2->code.' — '.$cc2->name, $bWaris['cost_centers'] ?? [], true),
    json_encode($bWaris['cost_centers'] ?? null));
$ok('jurnal dua cost center menampilkan KEDUANYA',
    $bCampur && count($bCampur['cost_centers'] ?? []) === 2, json_encode($bCampur['cost_centers'] ?? null));
$ok('jurnal yang kurang menandai akunnya',
    $bKurang && count($bKurang['cost_center_kurang'] ?? []) >= 1, json_encode($bKurang['cost_center_kurang'] ?? null));
$ok('jurnal yang lengkap tidak ditandai kurang', $bWaris && ($bWaris['cost_center_kurang'] ?? []) === []);

echo "\n=== 9. SARINGAN COST CENTER BENAR-BENAR MENYARING ===\n";
$rq2 = \Illuminate\Http\Request::create('/business-support/gl/journals/detail', 'GET', [
    'date_from' => now()->startOfYear()->toDateString(), 'date_to' => now()->endOfYear()->toDateString(),
    'f_cost_center' => (string) $cc2->id,
]);
$rq2->setUserResolver(fn () => $user);
$rq2->headers->set('X-Inertia', 'true');
$rq2->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
$props2 = json_decode((string) $ctl->index($rq2)
    ->toResponse($rq2)->getContent(), true)['props'] ?? [];
$nomor2 = collect($props2['journals']['data'] ?? [])->pluck('journal_number')->all();
$ok('jurnal ber-cost-center itu ikut tersaring', in_array($warisHeader->journal_number, $nomor2, true), json_encode($nomor2));
$ok('jurnal tanpa cost center TIDAK ikut', ! in_array($tanpaCc->journal_number, $nomor2, true));

echo "\n=== 10. SUMBER LAYAR (Vue) ===\n";
$vue = file_get_contents(resource_path('js/Pages/BusinessSupport/Gl/Journals/Index.vue')) ?: '';
$ok('kepala kolom Cost Center ada', str_contains($vue, '>Cost Center</th>'));
$ok('kepala kolom Baris sudah hilang', ! str_contains($vue, '>Baris</th>'));
$ok('kepala kolom Anggaran sudah hilang', ! str_contains($vue, '>Anggaran</th>'));
$ok('saringan f_cost_center terpasang', str_contains($vue, "pilihKolom('f_cost_center'"));
$ok('saringan f_lines & f_budget tidak ada lagi',
    ! str_contains($vue, "'f_lines'") && ! str_contains($vue, "'f_budget'"));
$ok('lines_count tidak lagi digambar', ! str_contains($vue, 'j.lines_count'));
$ok('penanda kekurangan cost center digambar', str_contains($vue, 'cost_center_kurang'));

} finally {
    DB::rollBack();
}

echo "\n=== HASIL ===\n";
echo "  lulus: $lulus · gagal: $gagal\n";
echo $gagal === 0 ? "  SEMUA LULUS\n" : "  ADA YANG GAGAL\n";
