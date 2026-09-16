<?php

/**
 * Master Data KPR — kelompok kolom "Collection Cash" (Angsuran Cash 1..3).
 *
 * DARI MANA DATANYA. Kwitansi (BKM) hidup di aplikasi Front Office, sedangkan layar ini berjalan
 * di Back Office; keduanya basis data terpisah. Yang menjembatani adalah JADWAL TAGIHAN yang
 * tersinkron ke BO — mekanisme yang sama persis dengan kolom UTJ & DP di sebelahnya, dan itulah
 * yang membuat kolom ini ikut terisi begitu kwitansinya dicetak, tanpa penautan manual.
 *
 * DUA HAL YANG DIJAGA, dan keduanya tidak memunculkan galat apa pun kalau salah:
 *
 *  1. 'ANG' DIPAKAI BERSAMA cicilan cash DAN pencairan KPR (PaymentType::Angsuran — "Cicilan KPR
 *     atau cash bertahap"). Tanpa penyaring skema, pencairan bank akan tampil di kolom "Angsuran
 *     Cash" dan terbaca sebagai uang masuk dari customer. Angkanya benar, artinya salah total.
 *  2. URUTAN SLOTNYA. Cash 3x mengisi slot 1-2-3 menurut urutan pembayaran. Slot yang tertukar
 *     memindahkan tanggal & nominal ke kolom tetangga tanpa satu pun tanda.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-master-kpr-collection-cash.php';"
 */

use App\Http\Controllers\Web\BankLoan\MasterDataKprController;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = \App\Models\User::all()->first(fn ($u) => $u->can('bankloan.application.view')) ?? \App\Models\User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

DB::beginTransaction();

try {
    $tenantId  = (int) DB::table('tenants')->orderBy('id')->value('id');
    $projectId = (int) DB::table('projects')->orderBy('id')->value('id');
    $unitId    = (int) DB::table('units')->orderBy('id')->value('id');
    $skemaKpr  = (int) DB::table('payment_schemes')->where('type', 'kpr')->value('id');
    $skemaCash = (int) DB::table('payment_schemes')->where('type', '!=', 'kpr')->value('id');
    $now = now();

    $ok('skema KPR & non-KPR tersedia', $skemaKpr > 0 && $skemaCash > 0, "kpr=$skemaKpr cash=$skemaCash");
    if (! $skemaKpr || ! $skemaCash || ! $unitId) { DB::rollBack(); echo "Prasyarat kurang.\n"; return; }

    $buatCustomer = fn (string $nama, string $kode) => (int) DB::table('customers')->insertGetId([
        'tenant_id' => $tenantId, 'code' => $kode, 'full_name' => $nama,
        'id_type' => 'ktp', 'id_number' => '327'.random_int(1000000000000, 9999999999999),
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $no = 0;
    $buatBooking = function (int $skemaId, string $namaSkema) use ($tenantId, $projectId, $unitId, $buatCustomer, $now, &$no): int {
        $n = ++$no;

        return (int) DB::table('bookings')->insertGetId([
            'tenant_id' => $tenantId, 'project_id' => $projectId, 'unit_id' => $unitId,
            'customer_id' => $buatCustomer('UJI Cash '.$n, 'UJI-CASH-'.$n),
            'booking_number' => 'UJI-SPR-'.$n.'-'.random_int(100, 999),
            'booking_date' => $now->toDateString(), 'payment_scheme_id' => $skemaId,
            'scheme_name' => $namaSkema, 'base_price' => 500000000, 'total_price' => 500000000,
            'created_by' => auth()->id(),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };

    /** Jadwal tagihan yang SUDAH dibayar. */
    $jadwal = function (int $bookingId, string $tipe, ?string $label, int $seq, string $tgl, float $nominal) use ($now) {
        DB::table('payment_schedules')->insert([
            'booking_id' => $bookingId, 'payment_type' => $tipe, 'label' => $label,
            'installment_no' => $seq, 'sequence' => $seq,
            'due_date' => $tgl, 'amount' => $nominal, 'paid_amount' => $nominal, 'paid_at' => $tgl,
            'status' => 'paid', 'created_at' => $now, 'updated_at' => $now,
        ]);
    };

    // Agar masuk layar Master Data KPR, booking non-KPR perlu punya inisiasi KPR.
    $inisiasi = fn (int $bookingId, int $i) => DB::table('kpr_initiations')->insert([
        'tenant_id' => $tenantId, 'initiation_number' => 'UJI-INIT-CASH-'.$i.'-'.random_int(100, 999),
        'customer_id' => DB::table('bookings')->where('id', $bookingId)->value('customer_id'),
        'booking_id' => $bookingId, 'current_stage' => 1, 'status' => 'in_progress',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // 1) Cash 3x — tiga angsuran berurutan.
    $b3 = $buatBooking($skemaCash, 'Cash 3x');
    $inisiasi($b3, 1);
    $jadwal($b3, 'BF', 'UTJ', 1, '2026-01-05', 3000000);
    /* DISISIPKAN TIDAK BERURUTAN — 3, lalu 1, lalu 2.
       Kalau ketiganya disisipkan menaik, urutan id kebetulan sama dengan urutan angsuran,
       sehingga slotnya tetap benar WALAU pengurutan eksplisitnya dicopot: ujinya hijau tanpa
       menguji apa pun. Dengan urutan sisipan diacak, hanya orderBy sequence yang bisa
       memulangkan 1-2-3. */
    $jadwal($b3, 'ANG', 'Angsuran 3', 3, '2026-04-12', 250000000);
    $jadwal($b3, 'ANG', 'Angsuran 1', 1, '2026-02-10', 100000000);
    $jadwal($b3, 'ANG', 'Angsuran 2', 2, '2026-03-11', 150000000);

    // 2) Cash 1x — satu angsuran saja.
    $b1 = $buatBooking($skemaCash, 'Cash 1x');
    $inisiasi($b1, 2);
    $jadwal($b1, 'ANG', 'Angsuran 1', 1, '2026-05-20', 480000000);

    // 3) KPR — ANG miliknya adalah PENCAIRAN, bukan angsuran cash.
    $bk = $buatBooking($skemaKpr, 'KPR 70% / DP 30%');
    $jadwal($bk, 'ANG', 'Pencairan 1', 1, '2026-06-01', 350000000);

    $ctl = new MasterDataKprController;
    $req = \Illuminate\Http\Request::create('/kpr/master-data', 'GET');
    $req->setUserResolver(fn () => $user);
    $req->headers->set('X-Inertia', 'true');
    $req->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $props = json_decode((string) $ctl->index($req)->toResponse($req)->getContent(), true)['props'] ?? [];
    $rows = collect($props['rows'] ?? []);

    $baris = fn (int $bookingId) => $rows->firstWhere('no_spr', DB::table('bookings')->where('id', $bookingId)->value('booking_number'));

    echo "\n=== 1. Cash 3x mengisi ketiga slot, sesuai urutan pembayaran ===\n";

    $r3 = $baris($b3);
    $ok('barisnya ada di Master Data KPR', $r3 !== null, 'booking cash tidak masuk layar');
    $ac = $r3['angsuran_cash'] ?? [];
    $ok('ada tepat 3 slot angsuran cash', is_array($ac) && count($ac) === 3, json_encode($ac));
    $ok('slot 1 = 10 Feb 2026 · 100 jt',
        ($ac[0]['t'] ?? null) === '2026-02-10' && (float) ($ac[0]['n'] ?? 0) === 100000000.0, json_encode($ac[0] ?? null));
    $ok('slot 2 = 11 Mar 2026 · 150 jt',
        ($ac[1]['t'] ?? null) === '2026-03-11' && (float) ($ac[1]['n'] ?? 0) === 150000000.0, json_encode($ac[1] ?? null));
    $ok('slot 3 = 12 Apr 2026 · 250 jt',
        ($ac[2]['t'] ?? null) === '2026-04-12' && (float) ($ac[2]['n'] ?? 0) === 250000000.0, json_encode($ac[2] ?? null));
    // Slot yang tertukar memindahkan angka ke kolom tetangga tanpa satu pun tanda.
    $ok('urutannya menaik, bukan acak',
        ($ac[0]['t'] ?? '') < ($ac[1]['t'] ?? '') && ($ac[1]['t'] ?? '') < ($ac[2]['t'] ?? ''), json_encode(array_column($ac, 't')));
    $ok('UTJ-nya tidak ikut masuk kolom angsuran cash',
        ! in_array(3000000.0, array_map(fn ($c) => (float) ($c['n'] ?? 0), $ac), true), json_encode($ac));
    $ok('UTJ tetap pada kolomnya sendiri', (float) ($r3['utj']['n'] ?? 0) === 3000000.0, json_encode($r3['utj'] ?? null));

    echo "\n=== 2. Cash 1x hanya mengisi slot pertama ===\n";

    $r1 = $baris($b1);
    $ok('barisnya ada', $r1 !== null);
    $ac1 = $r1['angsuran_cash'] ?? [];
    $ok('slot 1 terisi 20 Mei 2026 · 480 jt',
        ($ac1[0]['t'] ?? null) === '2026-05-20' && (float) ($ac1[0]['n'] ?? 0) === 480000000.0, json_encode($ac1[0] ?? null));
    // array_key_exists, BUKAN ?? : operator ?? ikut memicu pada nilai null, sehingga
    // ($x ?? 'ADA') === null tidak pernah benar dan pemeriksaannya tidak menguji apa pun.
    $ok('slot 2 ada tapi kosong', array_key_exists(1, $ac1) && $ac1[1] === null, json_encode($ac1[1] ?? '(kunci hilang)'));
    $ok('slot 3 ada tapi kosong', array_key_exists(2, $ac1) && $ac1[2] === null, json_encode($ac1[2] ?? '(kunci hilang)'));
    $ok('tetap 3 slot (kolomnya tidak menyusut)', count($ac1) === 3, (string) count($ac1));

    echo "\n=== 3. Pencairan KPR TIDAK boleh tampil sebagai angsuran cash ===\n";

    $rk = $baris($bk);
    $ok('baris KPR ada di layar', $rk !== null);
    $ack = $rk['angsuran_cash'] ?? [];
    $ok('ketiga slotnya kosong',
        ($ack[0] ?? null) === null && ($ack[1] ?? null) === null && ($ack[2] ?? null) === null,
        'pencairan bank terbaca sebagai uang masuk dari customer: '.json_encode($ack));

    echo "\n=== 4. Kolomnya tidak mengganggu yang sudah ada ===\n";

    $ok('kolom DP tetap ada bentuknya', is_array($r3['dp'] ?? null) && count($r3['dp']) === 6, json_encode($r3['dp'] ?? null));
    $ok('kolom retensi tetap ada bentuknya', is_array($r3['retensi'] ?? null), json_encode(array_slice((array) ($r3['retensi'] ?? []), 0, 2)));
    $ok('cara bayar terbaca dari skemanya', ($r3['cara_bayar'] ?? null) === 'Cash 3x', (string) ($r3['cara_bayar'] ?? ''));

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
} finally {
    DB::rollBack();
}
