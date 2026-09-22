<?php

declare(strict_types=1);

/**
 * Uji: tombol "Ajukan Pembatalan" (Pembatalan Kontrak) terkunci sampai Memo Rekom Batal
 * untuk SPR itu diterbitkan — oleh layar yang SESUAI dengan cara bayarnya.
 *
 * Gerbang ini melintasi DUA aplikasi: penandanya tinggal di
 * ipro_bo.kpr_cancel_recommendations, sedangkan tombolnya hidup di Front Office dan
 * membacanya lewat koneksi ipro_bo. Satu-satunya kunci penghubungnya adalah NOMOR SPR
 * (fo_kontrak.no_kontrak == bookings.booking_number).
 *
 * Penandanya kini ditulis DUA layar, dan itulah yang membuat kolom `source` menjadi bagian
 * dari gerbang, bukan sekadar catatan:
 *   - KPR                  ← Reject BI Checking / Reject Proses KPR (Back Office)
 *   - CASH & CASH BERTAHAP ← Collection → Rekom Batal Cash (Front Office)
 *
 * Yang dijaga, berurut dari yang paling mahal bila salah:
 *
 *  1. CARA BAYAR YANG TIDAK PUNYA PENERBIT MEMO TIDAK IKUT TERKUNCI. CICILAN belum punya
 *     layar yang menerbitkan rekom batalnya; menguncinya berarti kontrak itu tidak akan
 *     pernah bisa dibatalkan sama sekali — jalan buntu yang tidak terlihat sebagai
 *     kesalahan, hanya sebagai tombol mati.
 *  2. KPR DAN CASH TANPA PENANDA MEMANG TERKUNCI, dan alasannya terbaca (tooltip) —
 *     termasuk MENUNJUK LAYAR YANG BENAR untuk masing-masing.
 *  3. PENANDA DARI SUMBER YANG SALAH TIDAK MEMBUKA. Kunci uniknya (tenant_id, spr_number)
 *     hanya mengizinkan satu penanda per SPR, sementara cara bayar sebuah kontrak masih bisa
 *     berubah sesudah memonya terbit (KontrakGeneratorService::syncCaraBayar dipanggil tiap
 *     jadwal dibuat ulang). Tanpa pemeriksaan `source`, memo cash lama membuka kunci kontrak
 *     yang kini berjalan sebagai KPR — persis yang gerbangnya dibuat untuk cegah.
 *  4. PENANDA MILIK TENANT LAIN TIDAK MEMBUKA TOMBOL. Nomor SPR hanya unik di dalam satu
 *     tenant; tanpa penyaring tenant, SPR bernomor sama milik tenant lain akan membukanya.
 *  5. PENANDA CASH TANPA BOOKING TETAP MEMBUKA. SPR cash tidak selalu punya cerminan di
 *     ipro_bo.bookings; bila penandanya tidak bisa ditulis tanpa booking, memonya tercetak
 *     sementara tombolnya tetap mati.
 *  6. ipro_bo TAK TERJANGKAU → TOMBOL TIDAK DIKUNCI. Menahan pembatalan karena sambungan ke
 *     modul lain sedang putus adalah kegagalan yang tidak bisa dibaca siapa pun dari layar ini.
 *  7. Gerbangnya benar-benar dipasang pada aksinya (bukan ditulis lalu tak dipasang).
 *
 * CATATAN CARA UJI: keputusan gerbang diuji lewat helper privatnya (Reflection), bukan lewat
 * merakit komponen Livewire Filament. Yang diuji tetap kode yang sesungguhnya berjalan —
 * closure ->disabled() pada aksinya tidak memuat logika sendiri, ia hanya memanggil helper
 * ini; pemasangannya dijaga terpisah di bagian 7.
 *
 * Tulisan FO dibungkus transaksi lalu di-rollback; baris ipro_bo dibersihkan sendiri.
 *
 * Jalankan (butuh container yang membawa kode FO terbaru + akses ke ipro_bo):
 *   docker exec -e DB_DATABASE=jerp_verify jerp-verify php artisan tinker /var/www/html/tests/Manual/uji-ajukan-pembatalan-terkunci.php
 */

use App\Filament\Fo\Pages\PembatalanKontrak;
use App\Modules\BO\IproBridge\Services\RekomBatalCashService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal): void {
    if ($ok) { $lulus++; echo "  OK    {$nama}\n"; }
    else { $gagal++; echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n"; }
};

// Wajib: tanpa penanda "sampai ujung", galat di tengah jalan meninggalkan $gagal === 0 dan
// skrip ini akan mencetak SEMUA UJI LULUS padahal separuh pemeriksaannya tak pernah berjalan.
$tuntas = false;

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
$sprKpr = 'UJI-'.$TAG.'-KPR';
$sprCash = 'UJI-'.$TAG.'-CASH';
$sprCash3 = 'UJI-'.$TAG.'-CASH3';
$sprCicil = 'UJI-'.$TAG.'-CICIL';

$penanda = function (string $spr, string $source, ?int $bookingId, ?int $tenantId = null) use ($bo, $tenant): void {
    $bo->table('kpr_cancel_recommendations')->where('spr_number', $spr)->delete();
    $bo->table('kpr_cancel_recommendations')->insert([
        'tenant_id' => $tenantId ?? $tenant, 'booking_id' => $bookingId, 'spr_number' => $spr,
        'source' => $source, 'source_id' => 1,
        'recommended_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
};

echo "\n=== 0. Prasyarat ===\n";
$cek('ipro_bo punya tabel kpr_cancel_recommendations',
    Schema::connection((string) config('ipro.connection', 'ipro_bo'))->hasTable('kpr_cancel_recommendations'));
// Tanpa pelonggaran ini, penanda untuk SPR cash yang belum tercermin di ipro_bo kena FK 1452
// dan memonya tercetak sementara tombolnya tetap mati (lihat butir 5).
$kolom = $bo->selectOne(
    'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
    ['kpr_cancel_recommendations', 'booking_id'],
);
$cek('booking_id boleh kosong (migrasi 2026_09_22_180000 sudah jalan)',
    $kolom !== null && strtoupper((string) $kolom->IS_NULLABLE) === 'YES',
    'jalankan migrasi longgarkan_booking_pada_kpr_cancel_recommendations di ipro_bo');

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
    $kprK = $buat($sprKpr, 'KPR');
    $cash1 = $buat($sprCash, 'CASH');
    $cash3 = $buat($sprCash3, 'CASH_BERTAHAP');
    $cicil = $buat($sprCicil, 'CICILAN');

    // Penanda KPR ber-FK ke bookings, jadi satu baris booking sungguhan harus ada.
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
    $cek('kontrak CASH (1X) TERKUNCI', $nilaiGerbang($cash1) === false,
        'SPR cash bisa dibatalkan tanpa memo — instruksi ke admin sales tidak pernah terbit');
    $cek('kontrak CASH BERTAHAP (3X) TERKUNCI', $nilaiGerbang($cash3) === false);
    // Penjaga jalan buntu: CICILAN belum punya layar penerbit memo. Menguncinya membuat
    // kontraknya tidak akan pernah bisa dibatalkan sama sekali.
    $cek('kontrak CICILAN TETAP TERBUKA', $nilaiGerbang($cicil) === true,
        'cara bayar tanpa penerbit memo ikut terkunci — tidak akan pernah bisa dibatalkan');

    echo "\n=== 2. Sesudah memo diterbitkan layar yang sesuai ===\n";

    $penanda($sprKpr, 'reject_bi', $iproBooking);
    $cek('kontrak KPR TERBUKA (sumber reject_bi)', $nilaiGerbang($kprK) === true);
    $penanda($sprKpr, 'reject_kpr', $iproBooking);
    $cek('kontrak KPR TERBUKA (sumber reject_kpr)', $nilaiGerbang($kprK) === true);
    $cek('  kontrak lain tidak ikut terbuka', $nilaiGerbang($buat('UJI-'.$TAG.'-KPR2', 'KPR')) === false);

    // booking_id SENGAJA null: inilah keadaan normal SPR cash yang belum tersinkron ke BO.
    $penanda($sprCash, RekomBatalCashService::SUMBER, null);
    $cek('kontrak CASH TERBUKA oleh memo cash TANPA booking', $nilaiGerbang($cash1) === true,
        'SPR cash yang belum tercermin di ipro_bo tidak pernah bisa dibuka');
    $penanda($sprCash3, RekomBatalCashService::SUMBER, null);
    $cek('kontrak CASH BERTAHAP TERBUKA oleh memo cash', $nilaiGerbang($cash3) === true);

    echo "\n=== 3. Penanda dari sumber yang salah tidak membuka ===\n";

    // Kontrak yang cara bayarnya berubah sesudah memonya terbit: satu-satunya penanda yang
    // tersisa adalah milik keluarga yang lain, dan ia tidak boleh dihitung.
    $penanda($sprKpr, RekomBatalCashService::SUMBER, null);
    $cek('memo CASH tidak membuka kontrak KPR', $nilaiGerbang($kprK) === false,
        'memo cash lama membuka kunci kontrak yang kini berjalan sebagai KPR');
    $penanda($sprCash, 'reject_bi', $iproBooking);
    $cek('memo KPR tidak membuka kontrak CASH', $nilaiGerbang($cash1) === false);

    echo "\n=== 4. Penanda milik tenant lain tidak membuka tombol ===\n";

    // tenant_id ber-FK ke tabel tenants, jadi tenant kedua harus benar-benar ada.
    $tenantLain = (int) $bo->table('tenants')->insertGetId([
        'code' => 'UJI'.$TAG, 'name' => 'Tenant Uji Pembatalan',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $penanda($sprKpr, 'reject_bi', $iproBooking, $tenantLain);
    // Nomor SPR hanya unik DI DALAM satu tenant. Tanpa penyaring tenant, nomor yang sama
    // milik tenant lain akan membuka tombol di sini — dan pembatalannya menimpa SPR yang salah.
    $cek('KPR tetap terkunci walau ada penanda bernomor sama milik tenant lain',
        $nilaiGerbang($kprK) === false);
    $penanda($sprCash, RekomBatalCashService::SUMBER, null, $tenantLain);
    $cek('CASH tetap terkunci walau ada penanda bernomor sama milik tenant lain',
        $nilaiGerbang($cash1) === false);

    echo "\n=== 5. ipro_bo tak terjangkau: TIDAK mengunci ===\n";

    $konnAsli = config('ipro.connection');
    config(['ipro.connection' => 'koneksi_yang_tidak_ada']);
    $cek('gangguan sambungan tidak mematikan tombol KPR', $nilaiGerbang($kprK) === true,
        'tombol terkunci hanya karena modul lain tidak terjangkau');
    $cek('gangguan sambungan tidak mematikan tombol CASH', $nilaiGerbang($cash1) === true);
    config(['ipro.connection' => $konnAsli]);

    echo "\n=== 6. Gerbangnya benar-benar dipasang pada aksinya ===\n";

    // Gerbang yang ditulis tapi tak dipasang adalah kegagalan paling sunyi: ujinya hijau,
    // dan tombolnya tetap terbuka untuk semua orang.
    $src = file_get_contents((new ReflectionClass(PembatalanKontrak::class))->getFileName());
    $aksi = substr($src, strpos($src, "Action::make('ajukan')"));
    $aksi = substr($aksi, 0, strpos($aksi, '->form('));
    // Dicocokkan bahwa rekomBatalSiap ada DI DALAM ->disabled(), bukan sekadar "ada di
    // sekitarnya": `->disabled(false)` tetap menyisakan kata itu di baris tooltip di bawahnya,
    // sehingga pemeriksaan "ada ->disabled( DAN ada rekomBatalSiap" lolos untuk aksi yang
    // gerbangnya sudah dicopot.
    $cek('aksi "ajukan" memakai ->disabled() dengan gerbang itu',
        (bool) preg_match('/->disabled\((?:[^()]|\([^()]*\))*rekomBatalSiap/', $aksi), substr($aksi, 0, 200));
    // Sama seperti di atas: tooltipnya harus benar-benar BERTANYA pada gerbang. Tooltip yang
    // syaratnya dipatok akan diam untuk semua baris, dan tombol mati tanpa penjelasan adalah
    // satu-satunya hal yang terlihat operator.
    $cek('  alasan terkuncinya terbaca (tooltip)',
        (bool) preg_match('/->tooltip\((?:[^()]|\([^()]*\))*rekomBatalSiap/', $aksi)
        && str_contains($aksi, 'Memo Rekom Batal'));
    $cek('  tooltip menyebut nomor kontraknya', str_contains($aksi, '$record->no_kontrak'));
    // Tooltip yang selalu menyebut modul KPR menyesatkan untuk SPR cash: operator dikirim ke
    // layar yang tidak akan pernah memuat SPR-nya.
    $asal = $ref->getMethod('asalMemo');
    $asal->setAccessible(true);
    $cek('  tooltip menunjuk layar yang benar untuk cash',
        str_contains((string) $asal->invoke(null, $cash1), 'Rekom Batal Cash'));
    $cek('  tooltip menunjuk modul KPR untuk kontrak KPR',
        str_contains((string) $asal->invoke(null, $kprK), 'Reject BI Checking'));

    $tuntas = true;
} catch (\Throwable $e) {
    $gagal++;
    echo "\n  GAGAL (galat tak tertangani) — ".get_class($e).': '.$e->getMessage()."\n";
} finally {
    DB::rollBack();
    $bo->table('kpr_cancel_recommendations')->where('spr_number', 'like', 'UJI-'.$TAG.'%')->delete();
    $bo->table('bookings')->where('booking_number', 'like', 'UJI-'.$TAG.'%')->delete();
    $bo->table('customers')->where('code', 'CUST-'.$TAG)->delete();
    $bo->table('tenants')->where('code', 'UJI'.$TAG)->delete();

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    if ($gagal === 0 && $tuntas) { echo "  SEMUA UJI LULUS\n"; }
    echo "==================================================\n";
    echo "(data uji dibatalkan & baris ipro_bo dibersihkan)\n";
}
