<?php

/**
 * REALISASI vs BUDGET (KPR) — target akad per bulan vs realisasinya, plus import target.
 *
 * APA YANG DIJAGA DAN KENAPA.
 *   1. SATU BOOKING BISA PUNYA BEBERAPA APLIKASI KPR (ganti bank). Menghitung langsung dari
 *      kpr_akads membuat satu unit terhitung DUA KALI — dan jumlahnya tetap terlihat wajar,
 *      jadi tidak ada satu galat pun yang memberi tahu.
 *   2. AKAD YANG KEMUDIAN BATAL BUKAN REALISASI. Aplikasi ini punya dua definisi "sudah akad";
 *      untuk laporan realisasi yang berlaku adalah yang memperlakukan rejected/cancelled
 *      sebagai batal walau akadnya sempat terjadi.
 *   3. BARIS YTD BERHENTI DI BULAN TERAKHIR YANG ADA REALISASINYA, bukan di bulan berjalan —
 *      memuat bulan kosong di ujungnya membuat pencapaiannya terbaca lebih buruk dari
 *      sebenarnya.
 *   4. TARGET_VALUE DISIMPAN RUPIAH PENUH. Layar menyingkatnya ke "Rp M"; menyimpan yang sudah
 *      dibulatkan membuat selisih ratusan juta lenyap tanpa jejak.
 *   5. IMPORT ULANG MEMPERBARUI, bukan menumpuk baris kedua untuk bulan yang sama.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-realisasi-vs-budget-kpr.php';"
 */

use App\Domain\BankLoan\Models\KprCollectionTarget;
use App\Http\Controllers\Web\BankLoan\KprTargetRealisasiController;
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
$rp = fn ($v) => (int) round((float) $v);

$user = User::all()->first(fn ($u) => $u->can('bankloan.application.view')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
if (! $user->can('bankloan.application.view')) { echo "User tanpa izin bankloan.application.view — uji dihentikan.\n"; return; }
auth()->setUser($user);

$ctrl = app(KprTargetRealisasiController::class);

$props = function (array $q = []) use ($ctrl, $user): array {
    $r = Request::create('/x', 'GET', $q, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);
    app()->instance('request', $r);

    return $ctrl->index($r)->toResponse($r)->getData(true)['props'];
};
$impor = function (string $nama, string $isi, int $year) use ($ctrl, $user) {
    session()->forget(['error', 'success']);
    $tmp = tempnam(sys_get_temp_dir(), 'tg').'-'.$nama;
    file_put_contents($tmp, $isi);
    $r = Request::create('/x', 'POST', ['year' => $year], [], ['file' => new UploadedFile($tmp, $nama, null, null, true)]);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $user);

    return $ctrl->import($r);
};
$pesan = function ($resp, string $k): string {
    $v = $resp->getSession()?->get($k);

    return $v === null ? '' : (is_string($v) ? $v : (string) json_encode($v));
};

$TAHUN = 2043; // masa khayalan

$tenant = tenant_id() ?? default_tenant_id();

/* Dua nomor booking SEMU. Yang diuji berkas ini adalah cara rekapnya menghitung — dedupe per
   booking, penyaring status, penempatan bulan — bukan keabsahan rantai booking → customer →
   unit → skema bayar. Membangun rantai itu lengkap berarti menyalin belasan tabel master hanya
   untuk memperoleh dua baris aplikasi, dan pada basis data yang bookings-nya memang kosong uji
   ini akan selamanya terlewat. Pola & alasannya sama dengan uji-kpr-retensi-sinkron.php. */
$booking = [999801, 999802, 999803];

DB::beginTransaction();
DB::statement('SET FOREIGN_KEY_CHECKS=0');

try {
    $nomor = 0;
    /** Buat aplikasi KPR + akadnya. */
    /* plafon_requested sengaja DIBUAT BERBEDA dari plafon_approved. Bila keduanya sama, menukar
       kolom yang dipakai rekap tidak mengubah satu angka pun — ujinya lulus tanpa membuktikan
       bahwa yang dihitung memang plafon yang DISETUJUI. */
    $buat = function (int $bookingId, string $status, ?string $akadAt, float $plafon) use (&$nomor, $tenant, $TAHUN): int {
        $nomor++;
        $id = DB::table('kpr_applications')->insertGetId([
            'tenant_id' => $tenant, 'application_number' => 'UJI-RB-'.$TAHUN.'-'.$nomor,
            'booking_id' => $bookingId,
            // Kolom NOT NULL tanpa bawaan — diisi nilai semu; rekapnya tidak pernah membacanya.
            'customer_id' => 1, 'bank_partner_id' => 1,
            'plafon_requested' => $plafon * 7, 'plafon_approved' => $plafon,
            'submitted_at' => sprintf('%04d-01-05', $TAHUN), 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($akadAt !== null) {
            DB::table('kpr_akads')->insert([
                'kpr_application_id' => $id, 'akad_at' => $akadAt, 'status' => 'done',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    };

    echo "\n=== 1. REALISASI DIHITUNG DARI AKAD ===\n";

    $buat($booking[0], 'akad', sprintf('%04d-02-10 10:00:00', $TAHUN), 1_000_000_000);
    $buat($booking[1], 'disbursed', sprintf('%04d-02-20 10:00:00', $TAHUN), 2_000_000_000);

    $p = $props(['year' => $TAHUN]);
    $feb = collect($p['rows'])->firstWhere('month', 2);
    $ok('Februari: 2 unit', $feb && (int) $feb['realisasi_unit'] === 2, json_encode($feb['realisasi_unit'] ?? null));
    $ok('Februari: nilai 3 miliar', $feb && $rp($feb['realisasi_value']) === 3_000_000_000, json_encode($feb['realisasi_value'] ?? null));
    $ok('bulan lain kosong', (int) (collect($p['rows'])->firstWhere('month', 3)['realisasi_unit'] ?? -1) === 0);
    $ok('nilai memakai plafon yang DISETUJUI, bukan yang diminta',
        $feb && $rp($feb['realisasi_value']) !== 3_000_000_000 * 7, json_encode($feb['realisasi_value'] ?? null));

    /* AKAD TAHUN LAIN TIDAK BOLEH IKUT. Tanpa fikstur ini, membuang saringan tahun tidak
       mengubah satu angka pun — seluruh data uji kebetulan berada di tahun yang sama. */
    /* Dipakai booking KETIGA, bukan booking[0]: aplikasi bertahun lain akan menjadi aplikasi
       TERBARU booking itu dan diam-diam mengubah fikstur dedupe di bagian 2 & 3. */
    $buat($booking[2], 'active', sprintf('%04d-02-10 10:00:00', $TAHUN - 1), 5_000_000_000);
    $pLain = $props(['year' => $TAHUN]);
    $febLain = collect($pLain['rows'])->firstWhere('month', 2);
    $ok('akad tahun sebelumnya tidak ikut terhitung',
        $rp($febLain['realisasi_value']) === 3_000_000_000, json_encode($febLain['realisasi_value']));
    $pSebelum = $props(['year' => $TAHUN - 1]);
    $ok('dan memang terhitung pada tahunnya sendiri',
        $rp(collect($pSebelum['rows'])->firstWhere('month', 2)['realisasi_value']) === 5_000_000_000,
        json_encode(collect($pSebelum['rows'])->firstWhere('month', 2)['realisasi_value']));

    echo "\n=== 2. SATU BOOKING GANTI BANK — TIDAK TERHITUNG DUA KALI ===\n";

    /* Inilah jebakan yang paling mudah lolos: aplikasi lama booking[0] ditolak, lalu dibuat
       aplikasi baru di bank lain yang akhirnya akad. Menghitung langsung dari kpr_akads membuat
       unit yang sama terhitung dua kali, dan angkanya tetap terlihat wajar. */
    $buat($booking[0], 'akad', sprintf('%04d-02-15 10:00:00', $TAHUN), 1_500_000_000);

    $p = $props(['year' => $TAHUN]);
    $feb = collect($p['rows'])->firstWhere('month', 2);
    $ok('tetap 2 unit walau booking pertama punya 2 aplikasi berakad',
        (int) $feb['realisasi_unit'] === 2, json_encode($feb['realisasi_unit']));
    $ok('nilainya memakai aplikasi TERBARU booking itu (1,5 M + 2 M)',
        $rp($feb['realisasi_value']) === 3_500_000_000, json_encode($feb['realisasi_value']));

    echo "\n=== 3. AKAD YANG BATAL TIDAK IKUT ===\n";

    $buat($booking[1], 'cancelled', sprintf('%04d-03-05 10:00:00', $TAHUN), 9_000_000_000);
    $p = $props(['year' => $TAHUN]);
    $mar = collect($p['rows'])->firstWhere('month', 3);
    $ok('Maret tetap 0 unit walau akadnya ada', (int) $mar['realisasi_unit'] === 0, json_encode($mar['realisasi_unit']));
    $ok('nilai Maret tetap 0', $rp($mar['realisasi_value']) === 0, json_encode($mar['realisasi_value']));

    /* Dan booking[1] yang aplikasi TERBARUNYA batal tidak lagi dihitung di Februari — itulah
       konsekuensi jujur dari memakai aplikasi terbaru per booking. */
    $feb = collect($p['rows'])->firstWhere('month', 2);
    $ok('Februari menyesuaikan menjadi 1 unit', (int) $feb['realisasi_unit'] === 1, json_encode($feb['realisasi_unit']));

    echo "\n=== 4. IMPORT TARGET ===\n";

    $isi = SimpleXlsx::toString([
        KprTargetRealisasiController::IMPORT_HEADER,
        ['Januari', '93', '50600000000'],
        ['Februari', '52', '28900000000'],
        ['Mar', '42', '23900000000'],
        ['4', '100', '56200000000'],
    ]);
    $resp = $impor('target.xlsx', $isi, $TAHUN);
    $ok('import berhasil', $pesan($resp, 'error') === '', $pesan($resp, 'error'));
    $ok('empat bulan tersimpan', KprCollectionTarget::where('period_year', $TAHUN)->count() === 4,
        (string) KprCollectionTarget::where('period_year', $TAHUN)->count());

    $jan = KprCollectionTarget::where('period_year', $TAHUN)->where('period_month', 1)->first();
    $ok('target unit Januari = 93', (int) $jan->target_unit === 93, (string) $jan->target_unit);
    $ok('target value disimpan RUPIAH PENUH, bukan miliar',
        $rp($jan->target_value) === 50_600_000_000, (string) $jan->target_value);
    $ok('bulan boleh ditulis singkatan (Mar)',
        KprCollectionTarget::where('period_year', $TAHUN)->where('period_month', 3)->exists());
    $ok('bulan boleh ditulis angka (4)',
        KprCollectionTarget::where('period_year', $TAHUN)->where('period_month', 4)->exists());

    echo "\n=== 5. IMPORT ULANG MEMPERBARUI, BUKAN MENUMPUK ===\n";

    $isi2 = SimpleXlsx::toString([
        KprTargetRealisasiController::IMPORT_HEADER,
        ['Januari', '80', '40000000000'],
    ]);
    $impor('target2.xlsx', $isi2, $TAHUN);
    $ok('jumlah baris tetap 4', KprCollectionTarget::where('period_year', $TAHUN)->count() === 4,
        (string) KprCollectionTarget::where('period_year', $TAHUN)->count());
    $ok('target Januari ikut berubah menjadi 80',
        (int) KprCollectionTarget::where('period_year', $TAHUN)->where('period_month', 1)->value('target_unit') === 80);

    echo "\n=== 6. BULAN GANDA & BULAN NGAWUR DITOLAK ===\n";

    $ganda = SimpleXlsx::toString([
        KprTargetRealisasiController::IMPORT_HEADER, ['Mei', '10', '1'], ['Mei', '20', '2'],
    ]);
    $resp = $impor('ganda.xlsx', $ganda, $TAHUN);
    $ok('bulan yang muncul dua kali ditolak', str_contains($pesan($resp, 'error'), 'muncul dua kali'), $pesan($resp, 'error'));
    $ok('tidak ada baris Mei yang tersimpan',
        ! KprCollectionTarget::where('period_year', $TAHUN)->where('period_month', 5)->exists());

    $ngawur = SimpleXlsx::toString([KprTargetRealisasiController::IMPORT_HEADER, ['Entah', '10', '1']]);
    $resp = $impor('ngawur.xlsx', $ngawur, $TAHUN);
    $ok('bulan tak dikenal ditolak', str_contains($pesan($resp, 'error'), 'tidak dikenal'), $pesan($resp, 'error'));

    echo "\n=== 7. BARIS YTD & TOTAL ===\n";

    $p = $props(['year' => $TAHUN]);
    /* Realisasi terakhir ada di Februari, jadi YTD harus berhenti di Februari — bukan sampai
       Desember, dan bukan sampai bulan berjalan. */
    $ok('YTD berhenti di bulan terakhir yang ada realisasinya', (int) $p['ytd']['sampai'] === 2, json_encode($p['ytd']['sampai']));
    $ok('label YTD menyebut bulannya', str_contains((string) $p['ytd']['label'], 'Feb'), (string) $p['ytd']['label']);
    $ok('YTD target unit = Jan + Feb = 80 + 52', (int) $p['ytd']['target_unit'] === 132, json_encode($p['ytd']['target_unit']));
    $ok('TOTAL memakai seluruh 12 bulan target', (int) $p['total']['target_unit'] === 80 + 52 + 42 + 100,
        json_encode($p['total']['target_unit']));
    $ok('pencapaian unit dihitung', $p['total']['pencapaian'] !== null);

    echo "\n=== 8. SARINGAN BULAN ===\n";

    $satu = $props(['year' => $TAHUN, 'month' => 2]);
    $ok('penanda bulan dikirim balik', (int) $satu['month'] === 2, json_encode($satu['month']));
    $ok('baris tetap 12 — penyaringan dilakukan layar, TOTAL tetap setahun',
        count($satu['rows']) === 12, (string) count($satu['rows']));

    $ngawurBulan = $props(['year' => $TAHUN, 'month' => 99]);
    $ok('bulan di luar 1–12 jatuh ke Semua Bulan', (int) $ngawurBulan['month'] === 0, json_encode($ngawurBulan['month']));

    echo "\n=== 9. TEMPLATE ===\n";

    $reqT = Request::create('/x', 'GET');
    $reqT->setUserResolver(fn () => $user);
    app()->instance('request', $reqT);
    $tpl = $ctrl->template($reqT);
    $bin = $tpl->getContent();
    $ok('template .xlsx sungguhan', str_starts_with($bin, 'PK'), substr($bin, 0, 4));

    $tmpT = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($tmpT, $bin);
    $barisTpl = SimpleXlsx::rows($tmpT);
    $ok('template berisi header + 12 bulan', count($barisTpl) === 13, (string) count($barisTpl));
    $ok('judul kolomnya sesuai', array_map('strval', $barisTpl[0] ?? []) === KprTargetRealisasiController::IMPORT_HEADER,
        json_encode($barisTpl[0] ?? []));
    @unlink($tmpT);

    echo "\n──────────────────────────────────────────────────────────────────────────────\n";
    echo $gagal === 0 ? "SEMUA LULUS ($lulus)\n" : "ADA YANG GAGAL — lulus $lulus, gagal $gagal\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}
