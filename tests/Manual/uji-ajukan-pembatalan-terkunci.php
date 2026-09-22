<?php

declare(strict_types=1);

/**
 * Uji: tombol "Ajukan Pembatalan" (Pembatalan Kontrak) terkunci sampai modul KPR
 * menerbitkan Memo Rekom Batal untuk SPR itu.
 *
 * Gerbang ini melintasi DUA aplikasi: penandanya ditulis Back Office ke
 * ipro_bo.kpr_cancel_recommendations, sedangkan tombolnya hidup di Front Office dan
 * membacanya lewat koneksi ipro_bo. Satu-satunya kunci penghubungnya adalah NOMOR SPR
 * (fo_kontrak.no_kontrak == bookings.booking_number).
 *
 * Yang dijaga, berurut dari yang paling mahal bila salah:
 *
 *  1. KONTRAK NON-KPR TIDAK IKUT TERKUNCI. Layar ini memuat SPR & SPRS apa pun cara
 *     bayarnya, sedangkan rekom batal hanya lahir dari Reject BI Checking / Reject Proses
 *     KPR. Mengunci semuanya berarti kontrak CASH tidak akan pernah bisa dibatalkan sama
 *     sekali — jalan buntu yang tidak terlihat sebagai kesalahan, hanya sebagai tombol mati.
 *  2. KONTRAK KPR TANPA PENANDA MEMANG TERKUNCI, dan alasannya terbaca (tooltip).
 *  3. PENANDA MILIK TENANT LAIN TIDAK MEMBUKA TOMBOL. Nomor SPR hanya unik di dalam satu
 *     tenant; tanpa penyaring tenant, SPR bernomor sama milik tenant lain akan membukanya.
 *  4. ipro_bo TAK TERJANGKAU → TOMBOL TIDAK DIKUNCI. Menahan pembatalan karena sambungan ke
 *     modul lain sedang putus adalah kegagalan yang tidak bisa dibaca siapa pun dari layar ini.
 *  5. Aksinya benar-benar memakai gerbang itu (bukan gerbang yang ditulis lalu tak dipasang).
 *
 * CATATAN CARA UJI: keputusan gerbang diuji lewat helper privatnya (Reflection), bukan lewat
 * merakit komponen Livewire Filament. Yang diuji tetap kode yang sesungguhnya berjalan —
 * closure ->disabled() pada aksinya tidak memuat logika sendiri, ia hanya memanggil helper
 * ini; pemasangannya dijaga terpisah di bagian 5.
 *
 * Tulisan FO dibungkus transaksi lalu di-rollback; baris ipro_bo dibersihkan sendiri.
 *
 * Jalankan (butuh container yang membawa kode FO terbaru + akses ke ipro_bo):
 *   docker exec -e DB_DATABASE=jerp_verify jerp-verify php artisan tinker /var/www/html/tests/Manual/uji-ajukan-pembatalan-terkunci.php
 */

use App\Filament\Fo\Pages\PembatalanKontrak;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal): void {
    if ($ok) { $lulus++; echo "  OK    {$nama}\n"; }
    else { $gagal++; echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n"; }
};

$bo = DB::connection((string) config('ipro.connection', 'ipro_bo'));
$tenant = (int) config('ipro.tenant_id', 1);
$projectId = (int) config('ipro.project_id', 1);

// Gerbang + pengosong cache statisnya (cache per-request; uji ini banyak request semu).
$ref = new ReflectionClass(PembatalanKontrak::class);
$siap = $ref->getMethod('rekomBatalSiap');
$siap->setAccessible(true);
$cache = $ref->getProperty('rekomBatal');
$cache->setAccessible(true);
$nilaiGerbang = function (Kontrak $k) use ($siap, $cache): bool {
    $cache->setValue(null, null); // paksa baca ulang — kalau tidak, jawaban pertama membeku

    return (bool) $siap->invoke(null, $k);
};

$TAG = 'ZAP'.strtoupper(substr((string) getmypid(), -4));
$sprKpr  = 'UJI-'.$TAG.'-KPR';
$sprCash = 'UJI-'.$TAG.'-CASH';

echo "\n=== 0. Prasyarat ===\n";
$cek('ipro_bo punya tabel kpr_cancel_recommendations',
    Schema::connection((string) config('ipro.connection', 'ipro_bo'))->hasTable('kpr_cancel_recommendations'));

DB::beginTransaction();

try {
    $custId = (int) DB::table('fo_customers')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'code' => 'CUST-'.$TAG,
        'full_name' => 'Pembeli Uji Pembatalan', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $unitId = (int) DB::table('fo_units')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'kode_unit' => 'UNIT-'.$TAG,
        'cluster' => 'Cluster Uji', 'blok' => 'A', 'no_unit' => '21',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $buat = function (string $nomor, string $caraBayar) use ($tenant, $custId, $unitId) {
        DB::table('fo_kontrak')->insert([
            'tenant_id' => $tenant, 'no_kontrak' => $nomor, 'customer_id' => $custId, 'unit_id' => $unitId,
            'tgl_kontrak' => '2026-09-01', 'harga_jual' => 500000000, 'total_kontrak' => 500000000,
            'cara_bayar' => $caraBayar, 'status' => 'AKTIF', 'tahap' => 'SPR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Kontrak::where('no_kontrak', $nomor)->first();
    };
    $kprK  = $buat($sprKpr, 'KPR');
    $cashK = $buat($sprCash, 'CASH_BERTAHAP');

    // Penanda di ipro_bo ber-FK ke bookings, jadi satu baris booking sungguhan harus ada.
    // Dibuat di sini, bukan meminjam data contoh: tabel bookings di lingkungan ini kosong.
    $iproCust = (int) $bo->table('customers')->insertGetId([
        'tenant_id' => $tenant, 'code' => 'CUST-'.$TAG, 'full_name' => 'Pembeli Uji Pembatalan',
        'id_type' => 'ktp', 'id_number' => encrypt('3515000000000006'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $iproBooking = (int) $bo->table('bookings')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'booking_number' => $sprKpr,
        'customer_id' => $iproCust, 'unit_id' => (int) $bo->table('units')->value('id'),
        'payment_scheme_id' => (int) $bo->table('payment_schemes')->value('id'),
        'booking_date' => '2026-09-01', 'base_price' => 500000000, 'total_price' => 500000000,
        'status' => 'active', 'created_by' => (int) $bo->table('users')->min('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    echo "\n=== 1. Belum ada rekom batal ===\n";

    $cek('kontrak KPR TERKUNCI', $nilaiGerbang($kprK) === false);
    // Inilah yang membuat penguncian ini aman dipasang: kontrak non-KPR tidak pernah lewat
    // Reject BI Checking maupun Reject Proses KPR, jadi ia tidak akan pernah punya penanda.
    $cek('kontrak CASH BERTAHAP TETAP TERBUKA', $nilaiGerbang($cashK) === true,
        'kontrak non-KPR ikut terkunci — tidak akan pernah bisa dibatalkan');

    echo "\n=== 2. Sesudah modul KPR menerbitkan rekom batal ===\n";

    $bo->table('kpr_cancel_recommendations')->insert([
        'tenant_id' => $tenant, 'booking_id' => $iproBooking, 'spr_number' => $sprKpr,
        'source' => 'reject_bi', 'source_id' => 1, 'reason' => 'KOL 2',
        'recommended_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $cek('kontrak KPR TERBUKA', $nilaiGerbang($kprK) === true);
    $cek('  kontrak lain tidak ikut terbuka', $nilaiGerbang($buat('UJI-'.$TAG.'-KPR2', 'KPR')) === false);

    echo "\n=== 3. Penanda milik tenant lain tidak membuka tombol ===\n";

    // tenant_id ber-FK ke tabel tenants, jadi tenant kedua harus benar-benar ada.
    $tenantLain = (int) $bo->table('tenants')->insertGetId([
        'code' => 'UJI'.$TAG, 'name' => 'Tenant Uji Pembatalan',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $bo->table('kpr_cancel_recommendations')->where('spr_number', $sprKpr)->delete();
    $bo->table('kpr_cancel_recommendations')->insert([
        'tenant_id' => $tenantLain, 'booking_id' => $iproBooking, 'spr_number' => $sprKpr,
        'source' => 'reject_bi', 'source_id' => 1,
        'recommended_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Nomor SPR hanya unik DI DALAM satu tenant. Tanpa penyaring tenant, nomor yang sama
    // milik tenant lain akan membuka tombol di sini — dan pembatalannya menimpa SPR yang salah.
    $cek('tetap terkunci walau ada penanda bernomor sama milik tenant lain',
        $nilaiGerbang($kprK) === false);

    echo "\n=== 4. ipro_bo tak terjangkau: TIDAK mengunci ===\n";

    $konnAsli = config('ipro.connection');
    config(['ipro.connection' => 'koneksi_yang_tidak_ada']);
    $cek('gangguan sambungan tidak mematikan tombol', $nilaiGerbang($kprK) === true,
        'tombol terkunci hanya karena modul lain tidak terjangkau');
    config(['ipro.connection' => $konnAsli]);

    echo "\n=== 5. Gerbangnya benar-benar dipasang pada aksinya ===\n";

    // Gerbang yang ditulis tapi tak dipasang adalah kegagalan paling sunyi: ujinya hijau,
    // dan tombolnya tetap terbuka untuk semua orang.
    $src = file_get_contents((new ReflectionClass(PembatalanKontrak::class))->getFileName());
    $aksi = substr($src, strpos($src, "Action::make('ajukan')"));
    $aksi = substr($aksi, 0, strpos($aksi, '->form('));
    $cek('aksi "ajukan" memakai ->disabled() dengan gerbang itu',
        str_contains($aksi, '->disabled(') && str_contains($aksi, 'rekomBatalSiap'), substr($aksi, 0, 200));
    $cek('  alasan terkuncinya terbaca (tooltip)',
        str_contains($aksi, '->tooltip(') && str_contains($aksi, 'Memo Rekom Batal'));
    $cek('  tooltip menyebut nomor kontraknya', str_contains($aksi, '$record->no_kontrak'));
} finally {
    DB::rollBack();
    $bo->table('kpr_cancel_recommendations')->where('spr_number', 'like', 'UJI-'.$TAG.'%')->delete();
    $bo->table('bookings')->where('booking_number', 'like', 'UJI-'.$TAG.'%')->delete();
    $bo->table('customers')->where('code', 'CUST-'.$TAG)->delete();
    $bo->table('tenants')->where('code', 'UJI'.$TAG)->delete();

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    if ($gagal === 0) { echo "  SEMUA UJI LULUS\n"; }
    echo "==================================================\n";
    echo "(data uji dibatalkan & baris ipro_bo dibersihkan)\n";
}
