<?php

declare(strict_types=1);

/*
 * Uji sisi server Rincian Batal Akad: apakah controller benar-benar MENGIRIM customer_id.
 *
 * Uji SSR di sebelah (uji-batal-akad-gabung-customer.cjs) memberi customer_id sendiri, jadi ia
 * tidak bisa melihat kalau controllernya lupa mengirim. Dan kalau lupa, layarnya tidak error:
 * tiap baris jatuh ke kunci cadangan 'x'+id, jadi tampilannya kembali persis seperti sebelum
 * digabungkan. Tidak ada yang akan sadar.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback) — basis data kembali seperti semula.
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-batal-akad-payload.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Web\BusinessSupport\BatalAkadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// tenant_id() diisi middleware SetTenant; di luar request ia NULL dan TenantScope menyaring
// habis seluruh baris — nol baris yang terbaca seolah "memang tidak ada datanya".
$tenantId = (int) DB::table('tenants')->orderBy('id')->value('id');
app()->instance('tenant.id', $tenantId);

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal) {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};

DB::beginTransaction();

try {
    $projectId = (int) DB::table('projects')->orderBy('id')->value('id');
    $skemaId = (int) DB::table('payment_schemes')->orderBy('id')->value('id');
    $bankIds = DB::table('bank_partners')->orderBy('id')->limit(2)->pluck('id')->all();
    $unitIds = DB::table('units')->orderBy('id')->limit(3)->pluck('id')->all();
    $userId = (int) DB::table('users')->orderBy('id')->value('id');
    $now = now();

    $buatCustomer = function (string $nama, string $kode) use ($tenantId, $now): int {
        return (int) DB::table('customers')->insertGetId([
            'tenant_id' => $tenantId, 'code' => $kode, 'full_name' => $nama,
            'id_type' => 'ktp', 'id_number' => '327'.random_int(1000000000000, 9999999999999),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };

    // Dua orang BERBEDA yang kebetulan bernama sama — inilah yang membedakan pengelompokan
    // lewat id dari pengelompokan lewat nama.
    $custA = $buatCustomer('UJI Andi Saputra', 'UJI-C1');
    $custB = $buatCustomer('UJI Andi Saputra', 'UJI-C2');

    $buatKpr = function (int $custId, int $i, string $status) use (
        $tenantId, $projectId, $skemaId, $bankIds, $unitIds, $userId, $now
    ): int {
        $bookingId = (int) DB::table('bookings')->insertGetId([
            'tenant_id' => $tenantId, 'project_id' => $projectId,
            'booking_number' => 'UJI-BK-'.$i, 'customer_id' => $custId,
            'unit_id' => $unitIds[$i % count($unitIds)], 'payment_scheme_id' => $skemaId,
            'booking_date' => $now->toDateString(), 'base_price' => 500000000,
            'total_price' => 500000000, 'created_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) DB::table('kpr_applications')->insertGetId([
            'tenant_id' => $tenantId, 'application_number' => 'UJI-KPR-'.$i,
            'booking_id' => $bookingId, 'customer_id' => $custId,
            'bank_partner_id' => $bankIds[$i % count($bankIds)],
            'plafon_requested' => 400000000, 'submitted_at' => $now->toDateString(),
            'status' => $status, 'decided_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };

    // Customer A punya DUA pembatalan, customer B satu.
    $buatKpr($custA, 1, 'cancelled');
    $buatKpr($custA, 2, 'rejected');
    $buatKpr($custB, 3, 'cancelled');

    $ctl = app(BatalAkadController::class);
    $m = new ReflectionMethod(BatalAkadController::class, 'rows');
    $m->setAccessible(true);
    /** @var \Illuminate\Support\Collection<int,array<string,mixed>> $rows */
    $rows = $m->invoke($ctl, new Request, true);

    $uji = $rows->filter(fn ($r) => str_starts_with((string) $r['kpr_number'], 'UJI-KPR-'))->values();

    echo "\n=== 1. Controller mengirim customer_id ===\n";

    $cek('ketiga pengajuan uji terbaca', $uji->count() === 3, (string) $uji->count());
    $cek('customer_id ada di tiap baris',
        $uji->every(fn ($r) => array_key_exists('customer_id', $r)),
        'kunci customer_id tidak ada — layar akan diam-diam kembali tak tergabung');
    $cek('customer_id terisi, bukan null',
        $uji->every(fn ($r) => $r['customer_id'] !== null),
        (string) $uji->pluck('customer_id')->toJson());

    echo "\n=== 2. Pengelompokan memakai id, bukan nama ===\n";

    $perId = $uji->groupBy(fn ($r) => (string) $r['customer_id']);
    $perNama = $uji->groupBy(fn ($r) => (string) $r['customer']);

    $cek('dua orang meski namanya satu', $perId->count() === 2, (string) $perId->count());
    $cek('kalau dikelompokkan lewat nama hanya jadi satu — itu yang dihindari',
        $perNama->count() === 1, (string) $perNama->count());
    $cek('kelompok terbesar berisi dua pengajuan',
        $perId->max(fn ($g) => $g->count()) === 2,
        (string) $perId->max(fn ($g) => $g->count()));
    $cek('customer A memang dua', ($perId[(string) $custA] ?? collect())->count() === 2);
    $cek('customer B memang satu', ($perId[(string) $custB] ?? collect())->count() === 1);

    echo "\n=== 3. Ekspor Excel ikut Customer di depan ===\n";

    // export() dijaga izin; tanpa user ia 403. Dipakai user yang memang berhak, bukan
    // dilewati penjaganya — kalau gerbangnya rusak, uji ini harus ikut merah.
    $user = \App\Models\User::query()->find($userId);
    $reqExport = new Request;
    $reqExport->setUserResolver(fn () => $user);
    $cek('user uji memang berhak membuka layar ini',
        (bool) ($user?->canModule('businesssupport') || $user?->can('bankloan.application.view')),
        'user '.$userId.' tidak berhak — ganti user uji');
    $resp = $ctl->export($reqExport);
    $html = $resp->getContent();
    $posCust = strpos($html, '<th>Customer</th>');
    $posKpr = strpos($html, '<th>No. KPR</th>');

    $cek('kolom Customer ada', $posCust !== false);
    $cek('Customer mendahului No. KPR', $posCust !== false && $posKpr !== false && $posCust < $posKpr,
        "cust={$posCust} kpr={$posKpr}");
    // Kalau kepala kolomnya ditukar tetapi selnya tidak, nama customer akan tercetak di
    // bawah judul No. KPR — sama-sama terbaca, sama sekali salah.
    $barisA = null;
    foreach (explode('<tr>', $html) as $b) {
        if (str_contains($b, 'UJI-KPR-1')) {
            $barisA = $b;
            break;
        }
    }
    $sel = $barisA !== null ? array_map('strip_tags', explode('</td>', $barisA)) : [];
    $cek('sel nama customer duduk sebelum sel nomor KPR',
        isset($sel[1], $sel[2]) && str_contains($sel[1], 'UJI Andi Saputra') && str_contains($sel[2], 'UJI-KPR-1'),
        json_encode(array_slice($sel, 0, 4), JSON_UNESCAPED_UNICODE));

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    echo "==================================================\n";
} finally {
    DB::rollBack();
    $sisa = DB::table('kpr_applications')->where('application_number', 'like', 'UJI-KPR-%')->count()
        + DB::table('customers')->where('code', 'like', 'UJI-C%')->count();
    echo 'rollback selesai; sisa data uji: '.$sisa."\n";
}

exit($gagal > 0 ? 1 : 0);
