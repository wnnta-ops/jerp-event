<?php

/**
 * REALISASI KPR PER CLUSTER — dua tabel Exsum (Status Unit & Outstanding), diisi lewat import.
 *
 * APA YANG DIJAGA DAN KENAPA.
 *   1. IMPORT MENGGANTI SELURUH ISI TABELNYA, bukan menambahkan. Exsum adalah potret bulanan:
 *      baris cluster yang sudah tidak ada lagi di Exsum terbaru harus ikut hilang, bukan
 *      tertinggal sebagai sisa bulan lalu yang diam-diam terbaca sebagai keadaan sekarang.
 *   2. URUTANNYA MENGIKUTI KOLOM URUTAN, bukan abjad. Diurutkan abjad, "Cluster 3.10"
 *      mendahului "Cluster 3.2" dan alur tahapannya hilang.
 *   3. NILAI OUTSTANDING DISIMPAN APA ADANYA sebagai teks: "Rp 1,2 M (2 unit RUHA)" kehilangan
 *      justru bagian yang membuatnya terbaca bila dipaksa menjadi desimal.
 *   4. TANDA PISAH "–" PADA EXSUM BERARTI NOL pada tabel status, bukan angka yang hilang.
 *   5. KEDUA TABEL TERPISAH — mengimpor salah satunya tidak boleh menghapus yang lain.
 *   6. CATATAN KAKI diambil dari baris pertama yang mengisinya; Exsum menuliskannya sekali
 *      untuk seluruh tabel, bukan per baris.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-realisasi-kpr-per-cluster.php';"
 */

use App\Domain\BankLoan\Models\KprClusterOutstanding;
use App\Domain\BankLoan\Models\KprClusterStatus;
use App\Http\Controllers\Web\BankLoan\KprRealisasiClusterController;
use App\Models\User;
use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => $u->can('bankloan.application.view')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
if (! $user->can('bankloan.application.view')) { echo "User tanpa izin — uji dihentikan.\n"; return; }
auth()->setUser($user);

$ctrl = app(KprRealisasiClusterController::class);

$props = function () use ($ctrl, $user): array {
    $r = Request::create('/x', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);
    app()->instance('request', $r);

    return $ctrl->index($r)->toResponse($r)->getData(true)['props'];
};
$impor = function (string $jenis, array $rows) use ($ctrl, $user) {
    session()->forget(['error', 'success']);
    $tmp = tempnam(sys_get_temp_dir(), 'cl').'.xlsx';
    file_put_contents($tmp, SimpleXlsx::toString($rows));
    $r = Request::create('/x', 'POST', ['jenis' => $jenis], [], ['file' => new UploadedFile($tmp, 'exsum.xlsx', null, null, true)]);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $user);

    return $ctrl->import($r);
};
$pesan = function ($resp, string $k): string {
    $v = $resp->getSession()?->get($k);

    return $v === null ? '' : (is_string($v) ? $v : (string) json_encode($v));
};

DB::beginTransaction();

try {
    echo "\n=== 1. IMPORT TABEL STATUS UNIT ===\n";

    $H = KprRealisasiClusterController::HEADER_STATUS;
    $resp = $impor('status', [
        $H,
        ['1', 'Cluster 2.2 (JRK2 T2)', '169', '157', '158', '9', '-', '2', '-', 'Escrow 132 jt', 'Sumber: Exsum Collection Agustus 2026.'],
        ['2', 'Cluster 3.4 (JRK3 T4)', '222', '146', '163', '16', '17', '43', '-', 'Sisa Retensi 13,1 M', ''],
    ]);
    $ok('import berhasil', $pesan($resp, 'error') === '', $pesan($resp, 'error'));
    $ok('dua baris tersimpan', KprClusterStatus::count() === 2, (string) KprClusterStatus::count());

    $p = $props();
    $s = collect($p['status'])->keyBy('label');
    $ok('angka terbaca benar', (int) $s['Cluster 2.2 (JRK2 T2)']['total_unit'] === 169
        && (int) $s['Cluster 2.2 (JRK2 T2)']['kpr'] === 158, json_encode($s['Cluster 2.2 (JRK2 T2)'] ?? null));
    /* Tanda pisah pada Exsum berarti NOL, bukan angka yang hilang. */
    $ok('tanda pisah "-" dibaca sebagai 0', (int) $s['Cluster 2.2 (JRK2 T2)']['proses'] === 0
        && (int) $s['Cluster 2.2 (JRK2 T2)']['hold'] === 0, json_encode($s['Cluster 2.2 (JRK2 T2)']));
    $ok('kolom escrow disimpan apa adanya', $s['Cluster 2.2 (JRK2 T2)']['escrow'] === 'Escrow 132 jt',
        json_encode($s['Cluster 2.2 (JRK2 T2)']['escrow'] ?? null));

    $ok('TOTAL menjumlah seluruh baris', (int) $p['statusTotal']['total_unit'] === 169 + 222
        && (int) $p['statusTotal']['open_stock'] === 2 + 43, json_encode($p['statusTotal']));

    $ok('catatan kaki diambil dari baris yang mengisinya',
        $p['statusCatatan'] === 'Sumber: Exsum Collection Agustus 2026.', (string) ($p['statusCatatan'] ?? 'null'));

    echo "\n=== 2. URUTANNYA MENGIKUTI KOLOM URUTAN, BUKAN ABJAD ===\n";

    /* "Cluster 3.10" mendahului "Cluster 3.2" bila diurutkan abjad — alur tahapannya hilang.
       Urutan BARIS DI BERKAS sengaja DIBALIK terhadap kolom URUTAN: bila keduanya searah,
       membaca kolom URUTAN atau mengabaikannya sama-sama menghasilkan urutan yang benar, dan
       ujinya tidak membuktikan apa pun. */
    $impor('status', [
        $H,
        ['2', 'Cluster 3.10 (JRK3 T10)', '50', '40', '40', '10', '-', '-', '-', '', ''],
        ['1', 'Cluster 3.2 (JRK3 T2)', '102', '89', '89', '13', '-', '-', '-', '', ''],
    ]);
    $p = $props();
    $ok('baris pertama Cluster 3.2 — mengikuti kolom URUTAN, bukan urutan baris di berkas',
        ($p['status'][0]['label'] ?? '') === 'Cluster 3.2 (JRK3 T2)', json_encode(array_column($p['status'], 'label')));
    $ok('dan bukan urutan abjad', ($p['status'][1]['label'] ?? '') === 'Cluster 3.10 (JRK3 T10)',
        json_encode(array_column($p['status'], 'label')));

    echo "\n=== 3. IMPORT MENGGANTI SELURUH ISI, BUKAN MENAMBAH ===\n";

    $ok('baris lama (Cluster 2.2) sudah tidak ada lagi',
        ! collect($p['status'])->contains(fn ($r) => $r['label'] === 'Cluster 2.2 (JRK2 T2)'),
        json_encode(array_column($p['status'], 'label')));
    $ok('jumlahnya persis sebanyak berkas terakhir', count($p['status']) === 2, (string) count($p['status']));

    echo "\n=== 4. TABEL OUTSTANDING TERPISAH ===\n";

    $HO = KprRealisasiClusterController::HEADER_OS;
    $resp = $impor('os', [
        $HO,
        ['1', 'JRK 2 — Tahap 1', '-', '-', '-', 'Clear', 'Unit per cluster: JRK2 273.'],
        ['2', 'JRK 3 — Tahap 4', 'Rp 13,1 M', 'Rp 9,6 M (17 unit)', 'Rp 24,7 M (43 unit)', 'Retensi TO + BAST', ''],
    ]);
    $ok('import outstanding berhasil', $pesan($resp, 'error') === '', $pesan($resp, 'error'));

    $p = $props();
    $ok('tabel status TIDAK ikut terhapus', count($p['status']) === 2, (string) count($p['status']));
    $ok('dua baris outstanding tersimpan', count($p['os']) === 2, (string) count($p['os']));

    $o = collect($p['os'])->keyBy('label');
    /* Nilainya teks: angka datang bersama keterangan unitnya. */
    $ok('nilai outstanding disimpan apa adanya',
        $o['JRK 3 — Tahap 4']['proses'] === 'Rp 9,6 M (17 unit)', json_encode($o['JRK 3 — Tahap 4'] ?? null));
    $ok('kolom teks bebas terbaca', $o['JRK 2 — Tahap 1']['lain_lain'] === 'Clear',
        json_encode($o['JRK 2 — Tahap 1'] ?? null));
    $ok('catatan kaki outstanding terpisah dari catatan status',
        $p['osCatatan'] === 'Unit per cluster: JRK2 273.' && $p['statusCatatan'] !== $p['osCatatan'],
        json_encode([$p['osCatatan'], $p['statusCatatan']]));

    echo "\n=== 5. LABEL GANDA DITOLAK ===\n";

    $sebelum = KprClusterOutstanding::count();
    $resp = $impor('os', [$HO, ['1', 'JRK 9', 'a', 'b', 'c', 'd', ''], ['2', 'JRK 9', 'x', 'y', 'z', 'w', '']]);
    $ok('label yang muncul dua kali ditolak', str_contains($pesan($resp, 'error'), 'muncul dua kali'), $pesan($resp, 'error'));
    $ok('dan tidak ada yang tergantikan', KprClusterOutstanding::count() === $sebelum,
        json_encode([$sebelum, KprClusterOutstanding::count()]));

    echo "\n=== 6. BERKAS KOSONG & KOLOM KURANG ===\n";

    $resp = $impor('status', [$H]);
    $ok('berkas hanya berheader ditolak', str_contains($pesan($resp, 'error'), 'hanya berisi baris header'), $pesan($resp, 'error'));

    $resp = $impor('status', [['TOTAL_UNIT', 'REALISASI'], ['1', '2']]);
    $ok('kolom CLUSTER wajib ada', str_contains($pesan($resp, 'error'), 'CLUSTER'), $pesan($resp, 'error'));

    echo "\n=== 7. TEMPLATE ===\n";

    foreach (['status' => KprRealisasiClusterController::HEADER_STATUS, 'os' => KprRealisasiClusterController::HEADER_OS] as $jenis => $header) {
        $r = Request::create('/x', 'GET', ['jenis' => $jenis]);
        $r->setUserResolver(fn () => $user);
        app()->instance('request', $r);
        $bin = $ctrl->template($r)->getContent();
        $ok("template {$jenis} .xlsx sungguhan", str_starts_with($bin, 'PK'), substr($bin, 0, 4));

        $tmp = tempnam(sys_get_temp_dir(), 'tp').'.xlsx';
        file_put_contents($tmp, $bin);
        $baris = SimpleXlsx::rows($tmp);
        $ok("judul kolom template {$jenis} sesuai", array_map('strval', $baris[0] ?? []) === $header, json_encode($baris[0] ?? []));
        @unlink($tmp);
    }

    echo "\n──────────────────────────────────────────────────────────────────────────────\n";
    echo $gagal === 0 ? "SEMUA LULUS ($lulus)\n" : "ADA YANG GAGAL — lulus $lulus, gagal $gagal\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}
