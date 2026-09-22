<?php

/**
 * Memo Rekom Batal pada sub-modul **Reject BI Checking**.
 *
 * Permintaannya: tombol per-inisiasi yang dulu "Cetak" kini berfungsi sama persis dengan
 * "Memo Rekom Batal" di Reject Proses KPR, dan ditambah tombol massalnya. Kata "sama persis"
 * itulah yang paling sulit dijaga — bukan karena sulit dibuat sama hari ini, melainkan karena
 * dua salinan yang berdiri sendiri akan perlahan berbeda: mengganti nama penanda tangan atau
 * format nomor memo hanya akan mengubah salah satunya, dan yang tertinggal tetap mencetak
 * dokumen RESMI dengan data lama tanpa satu pun galat.
 *
 * Karena itu yang dijaga di sini, berurut dari yang paling mahal bila salah:
 *
 *  1. KEDUA SUB-MODUL MENGHASILKAN PAYLOAD YANG BENTUKNYA IDENTIK — kunci yang sama, kepala
 *     memo yang sama, penanda tangan yang sama. Diuji dengan membandingkan keduanya langsung,
 *     bukan dengan mencocokkan masing-masing ke daftar harapan yang ditulis dua kali.
 *  2. TOMBOL KEMBALI menunjuk sub-modul ASALNYA. Halaman memonya dipakai berdua; kalau
 *     tujuannya dipatok, operator dari BI Checking terlempar ke daftar yang salah dan
 *     kehilangan filter yang sedang ia pakai — tanpa terlihat seperti kesalahan.
 *  3. Penjaga yang sama dengan sub-modul sebelahnya: tanpa id → 404, inisiasi yang BUKAN
 *     ditolak → tidak ikut, tanpa izin → 403.
 *  4. Kedua jenis penolakan ikut (BI Checking maupun Batal Sepihak) — dua-duanya berakhir
 *     pada pembatalan unit, dan memo inilah dokumen pembatalannya.
 *  5. Barisnya urut dan bernomor 1..n, dengan keterangan diambil dari alasan penolakan BI.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-memo-rekom-batal-bi-checking.php';"
 */

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

$ctl = new KprDatabaseController;
$req = function ($u, array $data = []) {
    $r = Request::create('/uji', 'GET', $data, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $u);
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());

    return $r;
};
/** @return array{0:string,1:array} [component, props] */
$render = function ($res, $r) {
    $isi = json_decode((string) $res->toResponse($r)->getContent(), true);

    return [$isi['component'] ?? '', $isi['props'] ?? []];
};
/** Jalankan dan tangkap kode HTTP dari abort(). */
$kode = function (callable $fn): int {
    try { $fn(); return 200; }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { return $e->getStatusCode(); }
    catch (\Illuminate\Auth\Access\AuthorizationException) { return 403; }
};
/** Pesan penolakannya — dua keadaan berbeda sama-sama 404, yang membedakan hanya kalimatnya. */
$pesan = function (callable $fn): string {
    try { $fn(); return ''; }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { return $e->getMessage(); }
    catch (\Throwable $e) { return $e->getMessage(); }
};

DB::beginTransaction();

try {
    $uniq = strtoupper(substr(uniqid(), -5));

    // Cluster & unit dibuat SENDIRI, tidak meminjam data contoh. Cluster di lingkungan ini
    // sebagian sudah soft-delete, dan relasi `cluster` (HasTenant + SoftDeletes) memang benar
    // memulangkan null untuk itu — uji yang meminjamnya akan gagal karena keadaan data,
    // bukan karena kodenya.
    $tipeId = (int) DB::table('unit_types')->value('id');
    if (! $tipeId) { echo "Tidak ada unit_types — uji dihentikan.\n"; DB::rollBack(); return; }
    $tipeNama = (string) DB::table('unit_types')->where('id', $tipeId)->value('name');

    $clusterId = (int) DB::table('clusters')->insertGetId([
        'tenant_id' => $TENANT, 'project_id' => 1, 'code' => 'UJI'.$uniq,
        'name' => 'Cluster Uji Memo', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $unitId = (int) DB::table('units')->insertGetId([
        'tenant_id' => $TENANT, 'project_id' => 1, 'unit_code' => 'UJI-MB-'.$uniq,
        'cluster_id' => $clusterId, 'unit_type_id' => $tipeId,
        'base_price' => 500000000, 'current_price' => 500000000,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $unit = (object) ['id' => $unitId, 'unit_code' => 'UJI-MB-'.$uniq, 'cluster' => 'Cluster Uji Memo', 'tipe' => $tipeNama];
    $custId = (int) DB::table('customers')->insertGetId([
        'tenant_id' => $TENANT, 'code' => 'UJI-MB-'.$uniq, 'full_name' => 'DENA MIGITA PUTRI HARSANA',
        'id_type' => 'ktp', 'id_number' => encrypt('3515000000000004'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $skema = (int) DB::table('payment_schemes')->value('id');
    $bookingId = (int) DB::table('bookings')->insertGetId([
        'tenant_id' => $TENANT, 'project_id' => 1, 'booking_number' => 'UJI-MB-'.$uniq,
        'customer_id' => $custId, 'unit_id' => $unit->id, 'payment_scheme_id' => $skema,
        'booking_date' => '2026-09-01', 'base_price' => 500000000, 'total_price' => 500000000,
        'status' => 'active', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $buatInisiasi = function (string $status, ?array $bi) use ($TENANT, $custId, $bookingId, $uniq) {
        return (int) DB::table('kpr_initiations')->insertGetId([
            'tenant_id' => $TENANT, 'customer_id' => $custId, 'booking_id' => $bookingId,
            'initiation_number' => 'INIT/UJI/'.$uniq.'/'.uniqid(), 'status' => $status,
            'stages' => $bi === null ? null : json_encode(['bi_checking' => $bi]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $idBi = $buatInisiasi('rejected', ['reject_type' => 'bi_checking', 'reject_reason' => 'KOL 2', 'result' => 'kol2']);
    $idSepihak = $buatInisiasi('rejected', ['reject_type' => 'batal_sepihak', 'reject_reason' => 'mundur', 'result' => 'clear']);
    $idAktif = $buatInisiasi('in_progress', null);

    echo "\n=== 1. Rute & izinnya ===\n";

    $ok('rute kpr.database.reject-bi.memo-form terdaftar', \Illuminate\Support\Facades\Route::has('kpr.database.reject-bi.memo-form'));
    // Jalurnya harus SATU SEGMEN; kalau '…/memo-batal', rute '{initiation}' akan menangkapnya
    // lebih dulu dan mencoba memuat inisiasi bernama "memo-batal".
    $uri = \Illuminate\Support\Facades\Route::getRoutes()->getByName('kpr.database.reject-bi.memo-form')?->uri();
    $ok('  jalurnya tidak bertabrakan dengan rute {initiation}',
        $uri === 'kpr-database/reject-bi-checking-memo-batal', (string) $uri);

    $tanpaIzin = User::all()->first(fn ($u) => ! $u->can('bankloan.application.view'));
    if ($tanpaIzin) {
        $ok('tanpa izin ditolak 403',
            $kode(fn () => $ctl->rejectBiCheckingMemoForm($req($tanpaIzin, ['ids' => (string) $idBi]))) === 403);
    } else {
        echo "  (lewati uji 403 — semua user punya izin di lingkungan ini)\n";
    }

    echo "\n=== 2. Penjaga yang sama dengan Reject Proses KPR ===\n";

    $ok('tanpa ids → 404', $kode(fn () => $ctl->rejectBiCheckingMemoForm($req($user))) === 404);
    // Pesannya diperiksa, bukan hanya kodenya: "belum memilih apa pun" dan "yang dipilih tidak
    // memenuhi syarat" sama-sama 404, dan petugas hanya bisa membedakannya dari kalimatnya.
    // Tanpa ini, mencabut penjaga pertama tidak terlihat sama sekali.
    $ok('  dengan kalimat "pilih minimal satu"',
        str_contains(strtolower($pesan(fn () => $ctl->rejectBiCheckingMemoForm($req($user)))), 'pilih minimal satu'),
        $pesan(fn () => $ctl->rejectBiCheckingMemoForm($req($user))));
    $ok('ids yang tidak ada → 404', $kode(fn () => $ctl->rejectBiCheckingMemoForm($req($user, ['ids' => '99999999']))) === 404);
    $ok('  dengan kalimat yang BERBEDA (tidak ada yang cocok)',
        str_contains(strtolower($pesan(fn () => $ctl->rejectBiCheckingMemoForm($req($user, ['ids' => '99999999'])))), 'tidak ada inisiasi'),
        $pesan(fn () => $ctl->rejectBiCheckingMemoForm($req($user, ['ids' => '99999999']))));
    // Inisiasi yang masih berjalan tidak boleh ikut dibuatkan memo pembatalan.
    $ok('inisiasi yang BUKAN ditolak → 404',
        $kode(fn () => $ctl->rejectBiCheckingMemoForm($req($user, ['ids' => (string) $idAktif]))) === 404);

    $r = $req($user, ['ids' => $idBi.','.$idAktif]);
    [$komp, $p] = $render($ctl->rejectBiCheckingMemoForm($r), $r);
    $ok('campuran ditolak + berjalan: hanya yang ditolak ikut', count($p['rows'] ?? []) === 1, json_encode(array_column($p['rows'] ?? [], 'unit')));

    echo "\n=== 3. Isi memo untuk satu inisiasi ===\n";

    $r = $req($user, ['ids' => (string) $idBi]);
    [$komp, $p] = $render($ctl->rejectBiCheckingMemoForm($r), $r);
    $ok('memakai halaman memo yang sama', $komp === 'BankLoan/Database/RekomBatal', $komp);
    $baris = $p['rows'][0] ?? [];
    $ok('  cluster terisi dari unitnya', ($baris['cluster'] ?? '') === $unit->cluster, json_encode($baris['cluster'] ?? null));
    $ok('  tipe terisi dari unitnya', ($baris['tipe'] ?? '') === $unit->tipe, json_encode($baris['tipe'] ?? null));
    $ok('  kode unit terisi', ($baris['unit'] ?? '') === $unit->unit_code, json_encode($baris['unit'] ?? null));
    $ok('  nama user terisi', ($baris['user'] ?? '') === 'DENA MIGITA PUTRI HARSANA', json_encode($baris['user'] ?? null));
    // Keterangan = alasan penolakan BI. Kalau diambil dari kolom lain, memo pembatalan
    // terbit dengan alasan yang salah — dan itu dokumen yang ditandatangani direktur.
    $ok('  keterangan = alasan penolakan BI', ($baris['keterangan'] ?? '') === 'KOL 2', json_encode($baris['keterangan'] ?? null));
    $ok('  cara bayar KPR & status BATAL', ($baris['cara_bayar'] ?? '') === 'KPR' && ($baris['status'] ?? '') === 'BATAL');
    $ok('  kolom manual dibiarkan kosong (THP/Sales/Agent)',
        ($baris['thp'] ?? 'x') === '' && ($baris['sales'] ?? 'x') === '' && ($baris['agent'] ?? 'x') === '');
    $ok('  bernomor 1', ($baris['no'] ?? 0) === 1);
    $ok('  membawa id sebagai kunci baris', ($baris['id'] ?? null) === $idBi, json_encode($baris['id'] ?? null));

    echo "\n=== 4. Kedua jenis penolakan ikut, urut, dan bernomor ===\n";

    // Batal Sepihak juga berakhir pada pembatalan unit; memo ini dokumen pembatalannya.
    $r = $req($user, ['ids' => $idSepihak.','.$idBi]);
    [, $p] = $render($ctl->rejectBiCheckingMemoForm($r), $r);
    $rows = $p['rows'] ?? [];
    $ok('dua inisiasi → dua baris', count($rows) === 2, (string) count($rows));
    $ok('  urut menurut id, bukan urutan ids di URL',
        ($rows[0]['id'] ?? 0) === min($idBi, $idSepihak) && ($rows[1]['id'] ?? 0) === max($idBi, $idSepihak),
        json_encode(array_column($rows, 'id')));
    $ok('  penomorannya 1 lalu 2', ($rows[0]['no'] ?? 0) === 1 && ($rows[1]['no'] ?? 0) === 2);
    $alasan = array_column($rows, 'keterangan');
    $ok('  Batal Sepihak ikut beserta alasannya', in_array('mundur', $alasan, true), json_encode($alasan));

    echo "\n=== 5. Tombol kembali menunjuk sub-modul asalnya ===\n";

    $ok('dari BI Checking kembali ke Reject BI Checking',
        ($p['back']['label'] ?? '') === 'Reject BI Checking'
        && str_contains((string) ($p['back']['href'] ?? ''), 'reject-bi-checking'),
        json_encode($p['back'] ?? null));

    echo "\n=== 6. \"Sama persis\" dengan Reject Proses KPR — dibandingkan langsung ===\n";

    // Satu pengajuan KPR ditolak, untuk membandingkan payload kedua sub-modul.
    $bank = (int) DB::table('bank_partners')->value('id');
    if (! $bank) { echo "  (lewati — tidak ada bank_partners)\n"; }
    else {
        $appId = (int) DB::table('kpr_applications')->insertGetId([
            'tenant_id' => $TENANT, 'application_number' => 'KPR/UJI/'.$uniq, 'booking_id' => $bookingId,
            'customer_id' => $custId, 'bank_partner_id' => $bank, 'plafon_requested' => 400000000,
            'submitted_at' => '2026-09-01', 'status' => \App\Domain\BankLoan\Enums\KprStatus::Rejected->value,
            'reject_reason' => 'KOL 5', 'decided_at' => '2026-09-22',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rK = $req($user, ['ids' => (string) $appId]);
        [$kompK, $pK] = $render($ctl->rejectProcessMemoForm($rK), $rK);

        $ok('sub-modul sebelahnya memakai halaman yang sama', $kompK === 'BankLoan/Database/RekomBatal', $kompK);
        // Dibandingkan LANGSUNG, bukan dicocokkan ke daftar harapan yang ditulis dua kali:
        // daftar harapan yang disalin ikut basi bersama salinannya.
        $ok('  kunci payload-nya identik',
            array_keys($p) === array_keys($pK), json_encode([array_keys($p), array_keys($pK)]));
        $ok('  kepala memo identik', ($p['memo'] ?? null) === ($pK['memo'] ?? null), json_encode([$p['memo'] ?? null, $pK['memo'] ?? null]));
        $ok('  judul & tanggal lampiran identik',
            ($p['listHeading'] ?? null) === ($pK['listHeading'] ?? null) && ($p['listDate'] ?? null) === ($pK['listDate'] ?? null));
        // Blok tanda tangan: yang paling mudah berubah sebelah kalau disalin dua kali.
        $ok('  blok tanda tangan identik', ($p['signers'] ?? null) === ($pK['signers'] ?? null), json_encode([$p['signers'] ?? null, $pK['signers'] ?? null]));
        $ok('  bentuk barisnya identik',
            array_keys($p['rows'][0] ?? []) === array_keys($pK['rows'][0] ?? []),
            json_encode([array_keys($p['rows'][0] ?? []), array_keys($pK['rows'][0] ?? [])]));

        // Yang memang HARUS berbeda: tujuan tombol kembali.
        $ok('  hanya tombol kembali yang berbeda', ($p['back'] ?? null) !== ($pK['back'] ?? null));
        $ok('  dan sebelahnya tetap kembali ke Reject Proses KPR',
            ($pK['back']['label'] ?? '') === 'Reject Proses KPR'
            && str_contains((string) ($pK['back']['href'] ?? ''), 'reject-proses-kpr'),
            json_encode($pK['back'] ?? null));
    }

    echo "\n==================================================\n";
    echo "  LULUS $lulus · GAGAL $gagal\n";
    if ($gagal === 0) { echo "  SEMUA UJI LULUS\n"; }
    echo "==================================================\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}
