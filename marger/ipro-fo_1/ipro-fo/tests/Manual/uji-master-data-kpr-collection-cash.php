<?php

/**
 * Master Data KPR — kolom COLLECTION CASH (Angsuran Cash 1/2/3) yang terisi dari Kwitansi.
 *
 * Tiap sel di layar itu SEPASANG: Tanggal dari `payment_schedules.paid_at`, Nominal dari
 * `paid_amount`. Jembatan FO (IproMasterSync) kini mengisi keduanya — tanggalnya diambil dari
 * kwitansi (fo_bkm.tgl_bkm) lewat tautan fo_bkm.kontrak_tagihan_id. Uji ini menjaga sisi BO:
 * bahwa isi kedua kolom itu benar-benar sampai ke sel yang tepat, dan bahwa aturan-aturan
 * lama yang mudah terbawa hilang tetap berdiri.
 *
 * Yang dijaga, berurut dari yang paling mahal bila salah:
 *
 *  1. SLOT TIDAK BOLEH BERGESER. Angsuran Cash 1/2/3 diisi dari baris ANG urut
 *     sequence → installment_no → id. Satu pergeseran membuat uang angsuran ke-2 terbaca
 *     sebagai angsuran ke-1 — angkanya benar, barisnya salah, dan tidak ada galat apa pun.
 *  2. BOOKING BERSKEMA KPR TETAP DILEWATI. Di sana 'ANG' berarti PENCAIRAN BANK, bukan
 *     setoran customer; menampilkannya di "Angsuran Cash" akan terbaca sebagai uang masuk
 *     dari pembeli. Ini aturan lama yang paling mudah ikut terbuka saat menambal kolom.
 *  3. Tanggal kosong ditampilkan apa adanya (sel "—"), tidak dikarang dari due_date.
 *  4. Hanya TIGA slot; angsuran keempat dan seterusnya tidak menimpa slot pertama.
 *  5. Baris BF/DP tidak ikut jadi slot angsuran.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-master-data-kpr-collection-cash.php';"
 */

use App\Http\Controllers\Web\BankLoan\MasterDataKprController;
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

$ctl = new MasterDataKprController;
$req = function ($u, array $data = []) {
    $r = Request::create('/uji', 'GET', $data, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $u);
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());

    return $r;
};
$props = fn ($res, $r) => json_decode((string) $res->toResponse($r)->getContent(), true)['props'] ?? [];

DB::beginTransaction();

try {
    $unitIds = DB::table('units')->limit(2)->pluck('id')->all();
    $skemaCash = (int) DB::table('payment_schemes')->whereIn('type', ['cicilan', 'cash_keras'])->value('id');
    $skemaKpr  = (int) DB::table('payment_schemes')->where('type', 'kpr')->value('id');
    if (count($unitIds) < 2 || ! $skemaCash || ! $skemaKpr) {
        echo "Master belum cukup (butuh 2 unit + skema cash & kpr) — uji dihentikan.\n";
        DB::rollBack();

        return;
    }

    $uniq = strtoupper(substr(uniqid(), -5));
    $custId = DB::table('customers')->insertGetId([
        'tenant_id' => $TENANT, 'code' => 'UJI-CC-'.$uniq, 'full_name' => 'Pembeli Uji Collection Cash',
        'id_type' => 'ktp', 'id_number' => encrypt('3515000000000003'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $buatBooking = function (string $nomor, int $unitId, int $skemaId) use ($TENANT, $custId, $user) {
        return (int) DB::table('bookings')->insertGetId([
            'tenant_id' => $TENANT, 'project_id' => 1, 'booking_number' => $nomor,
            'customer_id' => $custId, 'unit_id' => $unitId, 'payment_scheme_id' => $skemaId,
            'booking_date' => '2026-08-13', 'base_price' => 562000000, 'total_price' => 562000000,
            'status' => 'active', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $sprCash = 'UJI-CC-'.$uniq.'-CASH';
    $sprKpr  = 'UJI-CC-'.$uniq.'-KPR';
    $idCash = $buatBooking($sprCash, (int) $unitIds[0], $skemaCash);
    $idKpr  = $buatBooking($sprKpr, (int) $unitIds[1], $skemaKpr);

    // Booking CASH masuk daftar Master Data KPR lewat INISIASI KPR-nya (layar ini memuat
    // booking berskema kpr ATAU yang punya inisiasi/aplikasi KPR) — persis seperti SPR cash
    // yang muncul di layar user.
    foreach ([$idCash, $idKpr] as $bid) {
        DB::table('kpr_initiations')->insert([
            'tenant_id' => $TENANT, 'booking_id' => $bid, 'customer_id' => $custId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $jadwal = function (int $bookingId, int $seq, string $tipe, string $label, float $nilai, ?float $bayar, ?string $tgl) use ($TENANT) {
        DB::table('payment_schedules')->insert([
            'booking_id' => $bookingId, 'installment_no' => $seq, 'sequence' => $seq,
            'payment_type' => $tipe, 'label' => $label, 'due_date' => '2026-09-01',
            'amount' => $nilai, 'paid_amount' => $bayar ?? 0, 'paid_at' => $tgl,
            'status' => $bayar !== null && $bayar >= $nilai ? 'paid' : 'outstanding',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    // Booking CASH: BF + 4 angsuran. Angsuran 1 & 2 sudah berkwitansi, 3 belum, 4 kelebihan slot.
    $jadwal($idCash, 1, 'BF',  'BOOKING FEE 1', 3000000, 3000000, '2026-08-13 00:00:00');
    $jadwal($idCash, 2, 'ANG', 'ANGSURAN 1', 187333331, 187333331, '2026-09-21 00:00:00');
    $jadwal($idCash, 3, 'ANG', 'ANGSURAN 2', 187333331, 187333331, '2026-09-27 00:00:00');
    $jadwal($idCash, 4, 'ANG', 'ANGSURAN 3', 187333338, 0, null);
    $jadwal($idCash, 5, 'ANG', 'ANGSURAN 4', 100000000, 100000000, '2026-10-05 00:00:00');
    // Booking KPR: baris ANG-nya adalah PENCAIRAN, bukan setoran customer.
    $jadwal($idKpr, 1, 'ANG', 'PENCAIRAN BANK', 500000000, 500000000, '2026-09-15 00:00:00');

    $r = $req($user);
    $p = $props($ctl->index($r), $r);
    $baris = collect($p['rows'] ?? [])->keyBy('no_spr');
    if (! $baris->has($sprCash)) {
        // Layar dibatasi 500 baris terbaru; kalau data uji terdorong keluar, seluruh uji
        // di bawah akan gagal untuk alasan yang salah.
        echo "Baris uji tidak masuk 500 teratas layar — uji dihentikan.\n";
        DB::rollBack();

        return;
    }

    echo "\n=== 1. Sel Angsuran Cash terisi dari jadwal yang sudah berkwitansi ===\n";

    $cash = $baris[$sprCash];
    $ang = $cash['angsuran_cash'] ?? [];
    $ok('barisnya ada di layar', $cash !== null);
    $ok('  ada tepat tiga slot', count($ang) === 3, (string) count($ang));
    $ok('  slot 1 — tanggal dari kwitansinya', ($ang[0]['t'] ?? null) === '2026-09-21', json_encode($ang[0] ?? null));
    $ok('  slot 1 — nominalnya', (float) ($ang[0]['n'] ?? 0) === 187333331.0, json_encode($ang[0] ?? null));
    $ok('  slot 2 — tanggal & nominalnya',
        ($ang[1]['t'] ?? null) === '2026-09-27' && (float) ($ang[1]['n'] ?? 0) === 187333331.0, json_encode($ang[1] ?? null));

    echo "\n=== 2. Yang belum berkwitansi tampil apa adanya, tidak dikarang ===\n";

    // Sel "—" di layar lahir dari t = null. due_date TIDAK boleh dipakai sebagai gantinya:
    // itu tanggal jatuh tempo, bukan tanggal uang masuk.
    $ok('slot 3 tanggalnya null (sel "—" di layar)',
        array_key_exists(2, $ang) && $ang[2]['t'] === null, json_encode($ang[2] ?? null));
    $ok('  nominalnya nol, bukan nilai tagihannya', (float) ($ang[2]['n'] ?? -1) === 0.0, json_encode($ang[2] ?? null));

    echo "\n=== 3. Slot tidak bergeser & tidak ditimpa angsuran keempat ===\n";

    // ANGSURAN 4 sudah dibayar 5 Oktober. Kalau slotnya diisi dengan menimpa, angka itu
    // akan muncul di slot 1 dan uang angsuran pertama lenyap dari layar tanpa jejak.
    $semuaTgl = array_map(fn ($c) => $c['t'] ?? null, $ang);
    $ok('urutan tanggalnya persis 21 Sep, 27 Sep, kosong', $semuaTgl === ['2026-09-21', '2026-09-27', null], json_encode($semuaTgl));
    $ok('  angsuran keempat TIDAK menimpa slot mana pun', ! in_array('2026-10-05', $semuaTgl, true), json_encode($semuaTgl));

    echo "\n=== 4. Booking Fee tidak ikut jadi slot angsuran ===\n";

    // BF punya kolomnya sendiri (Collection UTJ). Kalau ia bocor ke sini, slot 1 akan
    // berisi uang tanda jadi dan seluruh angsuran bergeser satu.
    $ok('slot 1 bukan tanggal Booking Fee', ($ang[0]['t'] ?? null) !== '2026-08-13', json_encode($ang[0] ?? null));
    $ok('  kolom UTJ-nya sendiri justru yang berisi Booking Fee',
        ($cash['utj']['t'] ?? null) === '2026-08-13', json_encode($cash['utj'] ?? null));

    echo "\n=== 5. Booking berskema KPR tetap dilewati (ANG = pencairan bank) ===\n";

    $kpr = $baris[$sprKpr] ?? null;
    $ok('baris KPR ada di layar', $kpr !== null);
    $angKpr = $kpr['angsuran_cash'] ?? [];
    $ok('  seluruh slotnya kosong', count(array_filter($angKpr, fn ($c) => $c !== null)) === 0, json_encode($angKpr));
    $ok('  pencairan bank TIDAK terbaca sebagai setoran customer',
        ! in_array('2026-09-15', array_map(fn ($c) => $c['t'] ?? null, $angKpr), true), json_encode($angKpr));

    echo "\n==================================================\n";
    echo "  LULUS $lulus · GAGAL $gagal\n";
    if ($gagal === 0) { echo "  SEMUA UJI LULUS\n"; }
    echo "==================================================\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}
