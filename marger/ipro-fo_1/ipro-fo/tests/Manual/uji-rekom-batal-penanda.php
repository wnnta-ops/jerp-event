<?php

/**
 * Penanda "Memo Rekom Batal sudah dibuat" — yang membuka tombol Ajukan Pembatalan di FO.
 *
 * Rekom batal bukan sekadar mencetak kertas: ia memberi instruksi ke admin sales untuk
 * membatalkan SPR. Karena itu yang dijaga di sini bukan "apakah barisnya tersimpan",
 * melainkan hal-hal yang kalau salah akan membatalkan SPR yang KELIRU atau membiarkan
 * SPR yang benar tetap terkunci — dua-duanya tanpa satu pun galat:
 *
 *  1. PENANDANYA BERKUNCI NOMOR SPR, bukan id BO. Nomor SPR adalah satu-satunya identitas
 *     yang dikenali kedua aplikasi; id inisiasi/pengajuan tidak berarti apa-apa di Front
 *     Office, dan penanda ber-id akan membuat tombol di sana tidak pernah terbuka.
 *  2. SATU SPR = SATU BARIS. Rekom batal kedua (memo dicetak ulang, atau sumber lain)
 *     memperbarui baris yang sama. Baris kembar membuat "sudah direkomendasikan?" punya
 *     lebih dari satu jawaban.
 *  3. KEDUA SUMBER menulis ke SATU tabel — Reject BI Checking (inisiasi) dan Reject Proses
 *     KPR (pengajuan). Ditaruh di tabelnya masing-masing, sisi FO harus menanyai dua tempat
 *     dan satu query yang terlupa berarti separuh SPR tetap terkunci selamanya.
 *  4. RUTE GET MEMO TETAP BEBAS EFEK SAMPING. Yang memberi instruksi adalah POST; kalau
 *     GET ikut mencatat, membuka ulang tab memo (atau prefetch peramban) diam-diam membuka
 *     tombol pembatalan untuk SPR yang tidak pernah dikonfirmasi siapa pun.
 *  5. Inisiasi TANPA booking tidak punya nomor SPR — memonya tetap boleh dicetak, tetapi
 *     penandanya tidak dibuat, dan itu tidak boleh menggagalkan seluruh permintaan.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-rekom-batal-penanda.php';"
 */

use App\Domain\BankLoan\Models\KprCancelRecommendation;
use App\Http\Controllers\Web\BankLoan\KprDatabaseController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => $u->can('bankloan.application.view')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);
$TENANT = (int) $user->tenant_id;

if (! Schema::hasTable('kpr_cancel_recommendations')) {
    echo "Tabel kpr_cancel_recommendations belum ada — jalankan migrasi 2026_09_22_140000 dulu.\n";
    return;
}

$ctl = new KprDatabaseController;
$post = function ($u, array $data = []) {
    $r = Request::create('/uji', 'POST', $data);
    $r->setUserResolver(fn () => $u);

    return $r;
};
$kode = function (callable $fn): int {
    try { $fn(); return 200; }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { return $e->getStatusCode(); }
    catch (\Illuminate\Auth\Access\AuthorizationException) { return 403; }
};
$penanda = fn (string $spr) => KprCancelRecommendation::where('tenant_id', $TENANT)->where('spr_number', $spr)->first();

DB::beginTransaction();

try {
    $uniq = strtoupper(substr(uniqid(), -5));
    $unitId = (int) DB::table('units')->value('id');
    $skema  = (int) DB::table('payment_schemes')->value('id');
    $bank   = (int) DB::table('bank_partners')->value('id');
    $custId = (int) DB::table('customers')->insertGetId([
        'tenant_id' => $TENANT, 'code' => 'UJI-RB-'.$uniq, 'full_name' => 'Pembeli Uji Rekom Batal',
        'id_type' => 'ktp', 'id_number' => encrypt('3515000000000005'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $buatBooking = function (string $spr) use ($TENANT, $custId, $unitId, $skema, $user) {
        return (int) DB::table('bookings')->insertGetId([
            'tenant_id' => $TENANT, 'project_id' => 1, 'booking_number' => $spr,
            'customer_id' => $custId, 'unit_id' => $unitId, 'payment_scheme_id' => $skema,
            'booking_date' => '2026-09-01', 'base_price' => 500000000, 'total_price' => 500000000,
            'status' => 'active', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $sprBi  = 'UJI-RB-'.$uniq.'-BI';
    $sprKpr = 'UJI-RB-'.$uniq.'-KPR';
    $bookBi  = $buatBooking($sprBi);
    $bookKpr = $buatBooking($sprKpr);

    $idBi = (int) DB::table('kpr_initiations')->insertGetId([
        'tenant_id' => $TENANT, 'customer_id' => $custId, 'booking_id' => $bookBi,
        'initiation_number' => 'INIT/RB/'.$uniq, 'status' => 'rejected',
        'stages' => json_encode(['bi_checking' => ['reject_type' => 'bi_checking', 'reject_reason' => 'KOL 2']]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Inisiasi ditolak TANPA booking — tidak punya nomor SPR sama sekali.
    $idYatim = (int) DB::table('kpr_initiations')->insertGetId([
        'tenant_id' => $TENANT, 'customer_id' => $custId, 'booking_id' => null,
        'initiation_number' => 'INIT/RB/'.$uniq.'/YATIM', 'status' => 'rejected',
        'stages' => json_encode(['bi_checking' => ['reject_reason' => 'tanpa unit']]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $idAktif = (int) DB::table('kpr_initiations')->insertGetId([
        'tenant_id' => $TENANT, 'customer_id' => $custId, 'booking_id' => $bookBi,
        'initiation_number' => 'INIT/RB/'.$uniq.'/AKTIF', 'status' => 'in_progress',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $idKpr = $bank ? (int) DB::table('kpr_applications')->insertGetId([
        'tenant_id' => $TENANT, 'application_number' => 'KPR/RB/'.$uniq, 'booking_id' => $bookKpr,
        'customer_id' => $custId, 'bank_partner_id' => $bank, 'plafon_requested' => 400000000,
        'submitted_at' => '2026-09-01', 'status' => \App\Domain\BankLoan\Enums\KprStatus::Rejected->value,
        'reject_reason' => 'KOL 5', 'decided_at' => '2026-09-22',
        'created_at' => now(), 'updated_at' => now(),
    ]) : 0;

    echo "\n=== 1. Penjaga: tidak sembarang permintaan bisa mencatat ===\n";

    $ok('tanpa ids → 404', $kode(fn () => $ctl->rejectBiCheckingRekomBatal($post($user))) === 404);
    $ok('inisiasi yang belum ditolak → 404',
        $kode(fn () => $ctl->rejectBiCheckingRekomBatal($post($user, ['ids' => (string) $idAktif]))) === 404);
    $tanpaIzin = User::all()->first(fn ($u) => ! $u->can('bankloan.application.view'));
    if ($tanpaIzin) {
        $ok('tanpa izin → 403',
            $kode(fn () => $ctl->rejectBiCheckingRekomBatal($post($tanpaIzin, ['ids' => (string) $idBi]))) === 403);
    }
    $ok('  tidak satu pun penolakan meninggalkan penanda', $penanda($sprBi) === null);

    echo "\n=== 2. Reject BI Checking mencatat penanda & mengalihkan ke memonya ===\n";

    $res = $ctl->rejectBiCheckingRekomBatal($post($user, ['ids' => (string) $idBi]));
    $ok('jawabannya pengalihan (bukan render langsung)', $res instanceof \Illuminate\Http\RedirectResponse,
        get_class($res));
    // Dialihkan ke rute GET memo: tab baru berakhir pada URL yang bisa dimuat ulang tanpa
    // mengulang POST — operator tidak pernah melihat kotak "kirim ulang formulir?".
    $ok('  menuju halaman memo Reject BI Checking',
        str_contains($res->getTargetUrl(), 'reject-bi-checking-memo-batal') && str_contains($res->getTargetUrl(), 'ids='.$idBi),
        $res->getTargetUrl());

    $p = $penanda($sprBi);
    $ok('penanda terbentuk', $p !== null);
    $ok('  berkunci NOMOR SPR, bukan id BO', ($p->spr_number ?? '') === $sprBi, (string) ($p->spr_number ?? '-'));
    $ok('  menyimpan booking_id-nya', (int) ($p->booking_id ?? 0) === $bookBi);
    $ok('  sumbernya ditandai reject_bi', ($p->source ?? '') === KprCancelRecommendation::SUMBER_BI);
    $ok('  merujuk inisiasi asalnya', (int) ($p->source_id ?? 0) === $idBi);
    $ok('  alasan penolakan BI ikut tercatat', ($p->reason ?? '') === 'KOL 2', (string) ($p->reason ?? '-'));
    $ok('  mencatat siapa & kapan', ($p->recommended_at ?? null) !== null && (int) ($p->recommended_by ?? 0) === (int) $user->id);

    echo "\n=== 3. Diulang tidak menggandakan ===\n";

    for ($i = 0; $i < 3; $i++) { $ctl->rejectBiCheckingRekomBatal($post($user, ['ids' => (string) $idBi])); }
    $ok('empat kali rekom batal tetap satu baris',
        KprCancelRecommendation::where('tenant_id', $TENANT)->where('spr_number', $sprBi)->count() === 1);

    echo "\n=== 4. Reject Proses KPR menulis ke TABEL YANG SAMA ===\n";

    if (! $idKpr) { echo "  (dilewati — tidak ada bank_partners)\n"; }
    else {
        $res = $ctl->rejectProcessRekomBatal($post($user, ['ids' => (string) $idKpr]));
        $ok('  mengalihkan ke memo Reject Proses KPR',
            str_contains($res->getTargetUrl(), 'reject-proses-kpr-memo-batal'), $res->getTargetUrl());
        $pk = $penanda($sprKpr);
        $ok('  penandanya ada di tabel yang sama', $pk !== null);
        $ok('  sumbernya ditandai reject_kpr', ($pk->source ?? '') === KprCancelRecommendation::SUMBER_KPR);
        $ok('  alasan penolakan pengajuan ikut', ($pk->reason ?? '') === 'KOL 5', (string) ($pk->reason ?? '-'));
        // Satu query atas satu tabel cukup bagi FO untuk mengetahui KEDUA sumbernya.
        $ok('  satu query menemukan kedua sumber',
            KprCancelRecommendation::whereIn('spr_number', [$sprBi, $sprKpr])->count() === 2);
    }

    echo "\n=== 5. Inisiasi tanpa booking: memo boleh, penanda tidak ===\n";

    // Tanpa booking ia tidak punya nomor SPR — dan nomor SPR adalah satu-satunya kunci yang
    // dikenali Front Office. Permintaannya TIDAK boleh gagal seluruhnya karenanya.
    $sebelum = KprCancelRecommendation::count();
    $res = $ctl->rejectBiCheckingRekomBatal($post($user, ['ids' => $idYatim.','.$idBi]));
    $ok('permintaannya tetap berhasil', $res instanceof \Illuminate\Http\RedirectResponse);
    $ok('  tidak melahirkan penanda tanpa nomor SPR', KprCancelRecommendation::count() === $sebelum,
        'bertambah '.(KprCancelRecommendation::count() - $sebelum));

    echo "\n=== 6. Rute GET memo TETAP bebas efek samping ===\n";

    // Kalau GET ikut mencatat, membuka ulang tab memo — atau prefetch peramban — diam-diam
    // membuka tombol pembatalan untuk SPR yang tidak pernah dikonfirmasi siapa pun.
    KprCancelRecommendation::where('spr_number', $sprBi)->delete();
    $r = Request::create('/uji', 'GET', ['ids' => (string) $idBi], [], [], ['HTTP_X_INERTIA' => 'true']);
    $r->setUserResolver(fn () => $user);
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $ctl->rejectBiCheckingMemoForm($r);
    $ok('membuka halaman memo TIDAK mencatat penanda', $penanda($sprBi) === null,
        'GET memo ikut mencatat — tombol pembatalan terbuka tanpa konfirmasi');

    echo "\n==================================================\n";
    echo "  LULUS $lulus · GAGAL $gagal\n";
    if ($gagal === 0) { echo "  SEMUA UJI LULUS\n"; }
    echo "==================================================\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}
