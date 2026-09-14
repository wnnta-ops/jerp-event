<?php

/**
 * REALISASI KPR PER BANK — unit akad per cluster per bank (kumulatif) + komposisi bank.
 *
 * APA YANG DIJAGA DAN KENAPA.
 *   1. SATU UNIT TIDAK BOLEH MUNCUL DI DUA KOLOM BANK. Satu booking bisa punya beberapa aplikasi
 *      KPR (ganti bank) — dan justru di layar inilah akibatnya paling terlihat salah: unit yang
 *      sama terhitung di bank lama DAN bank baru, sementara totalnya tetap terlihat wajar.
 *   2. AKAD YANG BATAL BUKAN REALISASI.
 *   3. DEFINISINYA SAMA DENGAN LAYAR REALISASI vs BUDGET. Dua laporan realisasi yang menghitung
 *      "akad" dengan cara berbeda akan saling membantah, dan pembacanya tidak punya cara menebak
 *      mana yang benar. Uji ini membandingkan keduanya secara langsung.
 *   4. KOLOM BANK DIBENTUK DARI DATA, bukan dipatok di kode — bank baru muncul sendiri.
 *   5. PERSENTASE SATU DESIMAL. Bank dengan 7 dari 915 akad = 0,8%; dibulatkan ke bilangan bulat
 *      ia menjadi 1% atau 0%, dan 0% terbaca sebagai "tidak ada sama sekali".
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-realisasi-kpr-per-bank.php';"
 */

use App\Http\Controllers\Web\BankLoan\KprRealisasiBankController;
use App\Http\Controllers\Web\BankLoan\KprTargetRealisasiController;
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
if (! $user->can('bankloan.application.view')) { echo "User tanpa izin — uji dihentikan.\n"; return; }
auth()->setUser($user);

$props = function (string $kelas, string $method, array $q = []) use ($user): array {
    $r = Request::create('/x', 'GET', $q, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);
    app()->instance('request', $r);

    return app($kelas)->{$method}($r)->toResponse($r)->getData(true)['props'];
};

$tenant = tenant_id() ?? default_tenant_id();
$TAHUN  = 2044;

/* Rantai booking → unit → cluster dibuat SEMU dengan FK dimatikan, sama seperti
   uji-kpr-retensi-sinkron.php. Yang diuji cara merekapnya, bukan keabsahan rantai masternya. */
DB::beginTransaction();
DB::statement('SET FOREIGN_KEY_CHECKS=0');

try {
    $nomor = 0;

    // Dua cluster & tiga bank semu.
    $cl = [];
    foreach (['UJI Cluster A', 'UJI Cluster B'] as $i => $nama) {
        $cl[$i] = (int) DB::table('clusters')->insertGetId([
            'tenant_id' => $tenant, 'project_id' => 990000 + $i, 'code' => 'UJICL'.$i,
            'name' => $nama, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $bk = [];
    foreach (['UJI BTN', 'UJI BRI', 'UJI MANDIRI'] as $i => $nama) {
        $bk[$i] = (int) DB::table('bank_partners')->insertGetId([
            'tenant_id' => $tenant, 'name' => $nama, 'code' => 'UJIBK'.$i,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $unitSeq = 0;
    /** Buat booking+unit pada sebuah cluster; kembalikan booking_id. */
    $buatBooking = function (int $clusterId) use (&$unitSeq, $tenant): int {
        $unitSeq++;
        $unitId = 990000 + $unitSeq;
        DB::table('units')->insert([
            'id' => $unitId, 'tenant_id' => $tenant, 'project_id' => 990000,
            'cluster_id' => $clusterId, 'unit_code' => 'UJI-U'.$unitSeq,
            // Kolom NOT NULL tanpa bawaan — rekapnya tidak pernah membacanya.
            'base_price' => 0, 'current_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bookingId = 990000 + $unitSeq;
        DB::table('bookings')->insert([
            'id' => $bookingId, 'tenant_id' => $tenant, 'project_id' => 990000,
            'booking_number' => 'UJI-BK-'.$unitSeq, 'customer_id' => 1, 'unit_id' => $unitId,
            'payment_scheme_id' => 1, 'booking_date' => sprintf('%04d-01-01', 2044),
            'base_price' => 0, 'total_price' => 0, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $bookingId;
    };

    $buatAkad = function (int $bookingId, int $bankId, string $status, string $akadAt) use (&$nomor, $tenant, $TAHUN): int {
        $nomor++;
        $id = (int) DB::table('kpr_applications')->insertGetId([
            'tenant_id' => $tenant, 'application_number' => 'UJI-PB-'.$TAHUN.'-'.$nomor,
            'booking_id' => $bookingId, 'customer_id' => 1, 'bank_partner_id' => $bankId,
            'plafon_requested' => 1_000_000_000, 'plafon_approved' => 1_000_000_000,
            'submitted_at' => sprintf('%04d-01-05', $TAHUN), 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kpr_akads')->insert([
            'kpr_application_id' => $id, 'akad_at' => $akadAt, 'status' => 'done',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };

    echo "\n=== 1. UNIT AKAD PER CLUSTER PER BANK ===\n";

    $b1 = $buatBooking($cl[0]); $buatAkad($b1, $bk[0], 'akad', sprintf('%04d-02-01', $TAHUN));
    $b2 = $buatBooking($cl[0]); $buatAkad($b2, $bk[0], 'active', sprintf('%04d-03-01', $TAHUN));
    $b3 = $buatBooking($cl[0]); $buatAkad($b3, $bk[1], 'disbursed', sprintf('%04d-04-01', $TAHUN));
    $b4 = $buatBooking($cl[1]); $buatAkad($b4, $bk[2], 'akad', sprintf('%04d-05-01', $TAHUN));

    $p = $props(KprRealisasiBankController::class, 'index');
    $baris = collect($p['rows'])->keyBy('cluster');

    $ok('kedua cluster muncul', $baris->has('UJI Cluster A') && $baris->has('UJI Cluster B'),
        collect($p['rows'])->pluck('cluster')->implode(', '));
    $ok('Cluster A total 3 unit', (int) ($baris['UJI Cluster A']['total'] ?? 0) === 3, json_encode($baris['UJI Cluster A']['total'] ?? null));
    $ok('Cluster A · UJI BTN = 2', (int) ($baris['UJI Cluster A']['bank']['UJI BTN'] ?? 0) === 2, json_encode($baris['UJI Cluster A']['bank'] ?? null));
    $ok('Cluster A · UJI BRI = 1', (int) ($baris['UJI Cluster A']['bank']['UJI BRI'] ?? 0) === 1);
    $ok('Cluster A · UJI MANDIRI = 0', (int) ($baris['UJI Cluster A']['bank']['UJI MANDIRI'] ?? -1) === 0);
    $ok('Cluster B total 1 unit', (int) ($baris['UJI Cluster B']['total'] ?? 0) === 1);

    echo "\n=== 2. KOLOM BANK DIBENTUK DARI DATA ===\n";

    foreach (['UJI BTN', 'UJI BRI', 'UJI MANDIRI'] as $nb) {
        $ok("kolom {$nb} ada", in_array($nb, $p['banks'], true), implode(', ', $p['banks']));
    }

    echo "\n=== 3. GANTI BANK — SATU UNIT TIDAK MUNCUL DI DUA KOLOM ===\n";

    /* Booking b1 pindah dari UJI BTN ke UJI MANDIRI: aplikasi lamanya tetap ada dan tetap
       berstatus akad. Tanpa dedupe, unit yang sama akan terhitung di KEDUA kolom bank. */
    $buatAkad($b1, $bk[2], 'akad', sprintf('%04d-06-01', $TAHUN));

    $p2 = $props(KprRealisasiBankController::class, 'index');
    $a2 = collect($p2['rows'])->keyBy('cluster')['UJI Cluster A'];

    $ok('total Cluster A TETAP 3 — tidak bertambah karena ganti bank', (int) $a2['total'] === 3, json_encode($a2['total']));
    $ok('kolom UJI BTN turun menjadi 1', (int) ($a2['bank']['UJI BTN'] ?? 0) === 1, json_encode($a2['bank']));
    $ok('kolom UJI MANDIRI pada Cluster A menjadi 1', (int) ($a2['bank']['UJI MANDIRI'] ?? 0) === 1);
    $ok('jumlah kolom bank = total baris',
        array_sum($a2['bank']) === (int) $a2['total'], json_encode([$a2['bank'], $a2['total']]));

    echo "\n=== 4. AKAD BATAL TIDAK IKUT ===\n";

    $b5 = $buatBooking($cl[1]); $buatAkad($b5, $bk[0], 'cancelled', sprintf('%04d-07-01', $TAHUN));
    $p3 = $props(KprRealisasiBankController::class, 'index');
    $b3row = collect($p3['rows'])->keyBy('cluster')['UJI Cluster B'];
    $ok('Cluster B tetap 1 unit walau ada akad yang batal', (int) $b3row['total'] === 1, json_encode($b3row['total']));

    echo "\n=== 5. KOMPOSISI BANK ===\n";

    $komp = collect($p3['komposisi'])->keyBy('bank');
    $ok('grand total = 4 unit', (int) $p3['grand'] === 4, json_encode($p3['grand']));
    $ok('komposisi memuat ketiga bank', $komp->count() === 3, $komp->keys()->implode(', '));
    $ok('UJI MANDIRI 2 unit = 50%', (int) $komp['UJI MANDIRI']['unit'] === 2 && (float) $komp['UJI MANDIRI']['persen'] === 50.0,
        json_encode($komp['UJI MANDIRI'] ?? null));
    /* Diurutkan MENURUN — pertanyaan yang dijawab kartu ini "bank mana yang paling banyak". */
    $urut = collect($p3['komposisi'])->pluck('unit')->all();
    $ok('diurutkan dari yang terbanyak', $urut === collect($urut)->sortDesc()->values()->all(), json_encode($urut));

    echo "\n=== 6. PERSENTASE BERDESIMAL SATU ANGKA ===\n";

    /* Bank bernilai kecil: 1 dari 13 = 7,7%. Bila dibulatkan ke bilangan bulat, angka sekecil
       ini mudah menjadi 8% — dan yang lebih kecil lagi menjadi 0%, yang terbaca "tidak ada". */
    for ($i = 0; $i < 9; $i++) {
        $bx = $buatBooking($cl[0]);
        $buatAkad($bx, $bk[1], 'akad', sprintf('%04d-08-01', $TAHUN));
    }
    $p4 = $props(KprRealisasiBankController::class, 'index');
    $k4 = collect($p4['komposisi'])->keyBy('bank');
    $ok('grand total 13 unit', (int) $p4['grand'] === 13, json_encode($p4['grand']));
    $ok('UJI BTN 1 dari 13 = 7,7% (bukan 8%)',
        abs((float) $k4['UJI BTN']['persen'] - 7.7) < 0.01, json_encode($k4['UJI BTN'] ?? null));

    echo "\n=== 7. SEPAKAT DENGAN LAYAR REALISASI vs BUDGET ===\n";

    /* Dua laporan realisasi yang menghitung "akad" dengan cara berbeda akan saling membantah.
       Diperiksa langsung, bukan diandaikan. */
    $rvb = $props(KprTargetRealisasiController::class, 'index', ['year' => $TAHUN]);
    $unitRvb = array_sum(array_column($rvb['rows'], 'realisasi_unit'));
    $ok('total unit setahun sama dengan Realisasi vs Budget',
        $unitRvb === (int) $p4['grand'], json_encode(['per_bank' => $p4['grand'], 'rvb' => $unitRvb]));

    echo "\n=== 7b. APLIKASI TANPA AKAD TIDAK IKUT ===\n";

    /* Status boleh saja sudah 'active', tetapi tanpa baris akad ia belum pernah diakadkan —
       dan tanpa tanggal akad ia juga tidak bisa ditempatkan di mana pun. Dijaga dua lapis
       (join akad + tanggal akad wajib ada); fikstur ini menguji akibatnya, bukan salah satu
       lapisnya, sebab masing-masing lapis sudah cukup sendirian. */
    $bTanpa = $buatBooking($cl[1]);
    $nomor++;
    DB::table('kpr_applications')->insert([
        'tenant_id' => $tenant, 'application_number' => 'UJI-PB-NOAKAD-'.$nomor,
        'booking_id' => $bTanpa, 'customer_id' => 1, 'bank_partner_id' => $bk[0],
        'plafon_requested' => 1_000_000_000, 'plafon_approved' => 1_000_000_000,
        'submitted_at' => sprintf('%04d-01-05', $TAHUN), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $pNo = $props(KprRealisasiBankController::class, 'index');
    $ok('aplikasi berstatus active tanpa baris akad tidak menambah hitungan',
        (int) $pNo['grand'] === 13, json_encode($pNo['grand']));

    echo "\n=== 8. TENANT LAIN TIDAK IKUT TERHITUNG ===\n";

    /* DB::table melewati global scope tenant, jadi penyaring tenant di controller ditulis
       EKSPLISIT. Tanpa fikstur ini, membuang penyaring itu tidak mengubah satu angka pun —
       di CLI tenant_id() memang null sehingga penyaringnya tidak pernah aktif. Di sini
       konteks tenant dipasang sengaja supaya penyaringnya benar-benar diuji. */
    $tenantLain = $tenant + 9000;
    $bLain = $buatBooking($cl[0]);
    DB::table('bookings')->where('id', $bLain)->update(['tenant_id' => $tenantLain]);
    $nomor++;
    $idLain = (int) DB::table('kpr_applications')->insertGetId([
        'tenant_id' => $tenantLain, 'application_number' => 'UJI-PB-LAIN-'.$nomor,
        'booking_id' => $bLain, 'customer_id' => 1, 'bank_partner_id' => $bk[0],
        'plafon_requested' => 1_000_000_000, 'plafon_approved' => 1_000_000_000,
        'submitted_at' => sprintf('%04d-01-05', $TAHUN), 'status' => 'akad',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('kpr_akads')->insert([
        'kpr_application_id' => $idLain, 'akad_at' => sprintf('%04d-09-01', $TAHUN),
        'status' => 'done', 'created_at' => now(), 'updated_at' => now(),
    ]);

    app()->instance('tenant.id', $tenant);
    $pT = $props(KprRealisasiBankController::class, 'index');
    $ok('konteks tenant memang aktif', tenant_id() === $tenant, var_export(tenant_id(), true));
    $ok('akad tenant lain TIDAK ikut terhitung', (int) $pT['grand'] === 13, json_encode($pT['grand']));

    app()->instance('tenant.id', $tenantLain);
    $pL = $props(KprRealisasiBankController::class, 'index');
    $ok('dan memang terhitung pada tenantnya sendiri', (int) $pL['grand'] === 1, json_encode($pL['grand']));
    app()->forgetInstance('tenant.id');

    echo "\n──────────────────────────────────────────────────────────────────────────────\n";
    echo $gagal === 0 ? "SEMUA LULUS ($lulus)\n" : "ADA YANG GAGAL — lulus $lulus, gagal $gagal\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}
