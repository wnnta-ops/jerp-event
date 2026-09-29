<?php

/**
 * Rincian COGS → "Tambah Unit Massal" → tab "Pilih dari Modul Unit (Set Up Sales)".
 *
 * Daftarnya adalah modul Unit yang sama dengan yang dilihat operator di Set Up Sales, disaring
 * menurut LOKASI & TAHAP yang diinginkan, lalu tinggal dicentang.
 *
 * Yang dijaga, berurut dari yang paling mahal:
 *
 *  1. UNIT BATAL TIDAK DITAMPILKAN. Ia bukan lagi bagian dari lahan yang dijual; mencentangnya
 *     akan mengecilkan tarif per m2 SELURUH unit lain — angkanya tetap keluar, tetap terlihat
 *     wajar, dan tidak ada satu pun galat.
 *  2. PENYARING LOKASI & TAHAP BENAR-BENAR MENYARING. Unit dari lokasi atau tahap lain yang
 *     ikut tercentang membebankan biaya cluster ini ke unit yang bukan miliknya.
 *  3. UNIT YANG SUDAH ADA DI RINCIAN DITANDAI, BUKAN DISEMBUNYIKAN. Menyembunyikannya membuat
 *     operator mengira unitnya belum pernah ditarik, lalu mencarinya berulang kali.
 *  4. BATAS JUMLAHNYA DISEBUT. Daftar yang terpotong diam-diam membuat operator mengira unit
 *     yang hilang memang tidak ada — dan unit yang tidak masuk Rincian tidak menanggung HPP
 *     sepeser pun.
 *  5. NO. SPR & NAMA CUSTOMER ikut terbawa: KONTRAK lebih dulu, baru reservasi — kontrak yang
 *     sudah ditandatangani menggantikan reservasinya.
 *  6. Kegagalan di sisi Front Office hanya mengosongkan daftarnya dengan keterangan, bukan
 *     menjatuhkan layar Rincian COGS.
 *
 * Seluruh tulisan — termasuk di basis data FO — dibungkus transaksi lalu DIBATALKAN.
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-pilih-unit-fo.php';"
 */

use App\Domain\BusinessSupport\Models\BsCogs2Periode;
use App\Domain\BusinessSupport\Models\BsCogs2Unit;
use App\Http\Controllers\Web\BusinessSupport\Cogs\Cogs2Controller as K;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$tuntas = false;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = \App\Models\User::all()->first(fn ($u) => $u->hasRole('SuperAdmin')) ?? \App\Models\User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);
$tenant = (int) $user->tenant_id;
$FO = (string) config('bankloan.fo_database', '');
$TAG = 'ZPU'.strtoupper(substr((string) getmypid(), -4));

$ctl = app(K::class);
$pilih = function (array $param) use ($ctl) {
    $r = \Illuminate\Http\Request::create('/uji', 'GET', $param);
    $r->setUserResolver(fn () => auth()->user());
    $m = new ReflectionMethod(K::class, 'pilihanUnitFo');
    $m->setAccessible(true);

    return fn (BsCogs2Periode $p) => $m->invoke($ctl, $r, $p);
};

DB::beginTransaction();

try {
    $proyekFo = (int) DB::table($FO.'.projects')->value('id');
    $lokA = (int) DB::table($FO.'.fo_lokasi')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $proyekFo, 'kode' => 'LA'.$TAG, 'nama' => 'LOKASI A '.$TAG,
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $lokB = (int) DB::table($FO.'.fo_lokasi')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $proyekFo, 'kode' => 'LB'.$TAG, 'nama' => 'LOKASI B '.$TAG,
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $tipeId = (int) DB::table($FO.'.fo_unit_types')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $proyekFo, 'code' => 'T'.$TAG, 'name' => 'TIPE UJI',
        'luas_tanah' => 100, 'luas_bangunan' => 45, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $buatUnit = function (int $lok, string $tahap, string $kode, string $status) use ($FO, $tenant, $proyekFo, $tipeId) {
        return (int) DB::table($FO.'.fo_units')->insertGetId([
            'tenant_id' => $tenant, 'project_id' => $proyekFo, 'lokasi_id' => $lok, 'tahap' => $tahap,
            'unit_type_id' => $tipeId, 'blok' => 'A', 'no_unit' => substr($kode, -3),
            'kode_unit' => $kode, 'luas_tanah' => 90, 'luas_lebih_tanah' => 5, 'luas_bangunan' => 36,
            'harga_jual' => 500_000_000, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $u1 = $buatUnit($lokA, '001', $TAG.'-A-001', 'TERSEDIA');
    $u2 = $buatUnit($lokA, '001', $TAG.'-A-002', 'TERJUAL');
    $buatUnit($lokA, '002', $TAG.'-A-003', 'BOOKING');      // tahap lain
    $buatUnit($lokA, '001', $TAG.'-A-004', 'BATAL');        // batal
    $buatUnit($lokB, '001', $TAG.'-B-001', 'TERSEDIA');     // lokasi lain

    $p = BsCogs2Periode::create([
        'tenant_id' => $tenant, 'lokasi_id' => $lokA, 'lokasi_kode' => 'LA'.$TAG,
        'lokasi_nama' => 'LOKASI A '.$TAG, 'tahap' => '001',
        'luas_lahan' => 1_000, 'saleable' => 500, 'rth' => 300, 'luas_bangunan' => 200,
    ]);

    echo "\n=== 1. Penyaring lokasi & tahap ===\n";

    $h = $pilih([])($p);   // tanpa parameter → memakai lokasi & tahap periode
    $kode = array_column($h['rows'], 'kode');
    $ok('tanpa parameter memakai lokasi & tahap periode',
        ($h['filters']['lokasi_id'] ?? null) === $lokA && ($h['filters']['tahap'] ?? null) === '001');
    $ok('  hanya unit lokasi A tahap 001 yang tampil',
        $kode === [$TAG.'-A-001', $TAG.'-A-002'], 'tampil: '.json_encode($kode));
    $ok('  unit tahap lain TIDAK ikut', ! in_array($TAG.'-A-003', $kode, true),
        'unit tahap lain yang ikut membebankan biaya cluster ini ke unit yang bukan miliknya');
    $ok('  unit lokasi lain TIDAK ikut', ! in_array($TAG.'-B-001', $kode, true));
    $ok('  unit BATAL TIDAK ikut', ! in_array($TAG.'-A-004', $kode, true),
        'unit batal bukan lagi bagian lahan yang dijual — membebaninya mengecilkan tarif seluruh unit lain');

    $hSemua = $pilih(['fo_lokasi' => $lokA, 'fo_tahap' => ''])($p);
    $ok('tahap dikosongkan → seluruh tahap lokasi itu',
        array_column($hSemua['rows'], 'kode') === [$TAG.'-A-001', $TAG.'-A-002', $TAG.'-A-003'],
        'tampil: '.json_encode(array_column($hSemua['rows'], 'kode')));
    $ok('  dan unit BATAL tetap tidak ikut',
        ! in_array($TAG.'-A-004', array_column($hSemua['rows'], 'kode'), true));

    $hLain = $pilih(['fo_lokasi' => $lokB, 'fo_tahap' => ''])($p);
    $ok('lokasi bisa diganti ke lokasi LAIN dari periodenya',
        array_column($hLain['rows'], 'kode') === [$TAG.'-B-001'],
        'satu periode COGS bisa memuat unit dari tahap atau lokasi yang berbeda');

    echo "\n=== 2. Pencarian ===\n";

    $hCari = $pilih(['fo_lokasi' => $lokA, 'fo_tahap' => '', 'fo_cari' => 'A-003'])($p);
    $ok('mencari kode unit menyaring daftarnya',
        array_column($hCari['rows'], 'kode') === [$TAG.'-A-003'],
        'tampil: '.json_encode(array_column($hCari['rows'], 'kode')));
    $hTipe = $pilih(['fo_lokasi' => $lokA, 'fo_tahap' => '', 'fo_cari' => 'TIPE UJI'])($p);
    $ok('  dan bisa dicari lewat nama tipe', count($hTipe['rows']) === 3);
    $hNihil = $pilih(['fo_lokasi' => $lokA, 'fo_tahap' => '', 'fo_cari' => 'TIDAK ADA'])($p);
    $ok('  yang tidak cocok memulangkan kosong, bukan seluruh daftar', $hNihil['rows'] === []);

    echo "\n=== 3. Unit yang sudah ada di Rincian ditandai ===\n";

    BsCogs2Unit::create(['tenant_id' => $tenant, 'periode_id' => $p->id,
        'unit_rumah' => $TAG.'-A-001', 'lt' => 90, 'lb' => 36, 'status' => 'belum', 'costing' => 'belum', 'urut' => 1]);
    $peta = collect($pilih([])($p)['rows'])->keyBy('kode');
    $ok('unit yang sudah ada TETAP tampil', $peta->has($TAG.'-A-001'),
        'menyembunyikannya membuat operator mengira unitnya belum pernah ditarik');
    $ok('  dan ditandai "sudah"', ($peta[$TAG.'-A-001']['sudah'] ?? null) === true);
    $ok('  sedangkan yang belum, tidak ditandai', ($peta[$TAG.'-A-002']['sudah'] ?? null) === false);

    // Kode unit di dua sistem berbeda besar-kecil hurufnya; penandaan yang peka huruf akan
    // menandai unit yang sama sebagai belum ada, lalu ia masuk dua kali.
    BsCogs2Unit::where('periode_id', $p->id)->update(['unit_rumah' => strtolower($TAG.'-A-001')]);
    $ok('  penandaannya tidak peka huruf besar-kecil',
        (collect($pilih([])($p)['rows'])->keyBy('kode')[$TAG.'-A-001']['sudah'] ?? null) === true);
    BsCogs2Unit::where('periode_id', $p->id)->delete();

    echo "\n=== 4. No. SPR & nama customer ===\n";

    $cust1 = (int) DB::table($FO.'.fo_customers')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $proyekFo, 'code' => 'C1'.$TAG,
        'full_name' => 'PELANGGAN RESERVASI', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $cust2 = (int) DB::table($FO.'.fo_customers')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $proyekFo, 'code' => 'C2'.$TAG,
        'full_name' => 'PELANGGAN KONTRAK', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // A-002 punya reservasi SAJA; A-001 punya reservasi DAN kontrak.
    DB::table($FO.'.fo_reservasi')->insert([
        'tenant_id' => $tenant, 'no_reservasi' => 'RSV-'.$TAG, 'customer_id' => $cust1, 'unit_id' => $u2,
        'tgl_reservasi' => '2026-06-01', 'status' => 'aktif', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table($FO.'.fo_reservasi')->insert([
        'tenant_id' => $tenant, 'no_reservasi' => 'RSV-LAMA-'.$TAG, 'customer_id' => $cust1, 'unit_id' => $u1,
        'tgl_reservasi' => '2026-05-01', 'status' => 'aktif', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table($FO.'.fo_kontrak')->insert([
        'tenant_id' => $tenant, 'no_kontrak' => 'KTR-'.$TAG, 'customer_id' => $cust2, 'unit_id' => $u1,
        'tgl_kontrak' => '2026-06-10', 'status' => 'aktif', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $h4 = collect($pilih([])($p)['rows'])->keyBy('kode');
    $ok('unit yang hanya punya reservasi memakai nomor reservasinya',
        ($h4[$TAG.'-A-002']['no_spr'] ?? null) === 'RSV-'.$TAG
        && ($h4[$TAG.'-A-002']['nama_customer'] ?? null) === 'PELANGGAN RESERVASI');
    $ok('unit yang sudah berkontrak memakai nomor KONTRAK, bukan reservasinya',
        ($h4[$TAG.'-A-001']['no_spr'] ?? null) === 'KTR-'.$TAG
        && ($h4[$TAG.'-A-001']['nama_customer'] ?? null) === 'PELANGGAN KONTRAK',
        'terbaca: '.json_encode([$h4[$TAG.'-A-001']['no_spr'] ?? null, $h4[$TAG.'-A-001']['nama_customer'] ?? null])
        .' — kontrak yang sudah ditandatangani menggantikan reservasinya');

    echo "\n=== 5. Luas & kelebihan tanah ===\n";

    $ok('luas tanah & bangunan terbawa apa adanya',
        abs((float) $h4[$TAG.'-A-001']['lt'] - 90) < 0.005 && abs((float) $h4[$TAG.'-A-001']['lb'] - 36) < 0.005);
    // Kelebihan tanah DITAMPILKAN terpisah, tidak dijumlahkan ke LT: tombol "Tarik Unit dari
    // Front Office" di layar yang sama juga memakai luas_tanah saja, dan dua jalan masuk yang
    // menghasilkan luas berbeda untuk unit yang sama tidak akan pernah muncul sebagai galat.
    $ok('kelebihan tanah dipisah, TIDAK dijumlahkan ke LT',
        abs((float) $h4[$TAG.'-A-001']['lt_lebih'] - 5) < 0.005
        && abs((float) $h4[$TAG.'-A-001']['lt'] - 90) < 0.005,
        'lt='.$h4[$TAG.'-A-001']['lt'].' lt_lebih='.$h4[$TAG.'-A-001']['lt_lebih']);

    echo "\n=== 6. Batas jumlah disebut, tidak dipotong diam-diam ===\n";

    $batas = (new ReflectionClass(K::class))->getConstant('BATAS_PILIHAN_UNIT');
    $ok('batasnya terbaca dari konstanta', is_int($batas) && $batas > 0, 'batas='.var_export($batas, true));

    $massal = [];
    for ($i = 1; $i <= $batas + 1; $i++) {
        $massal[] = [
            'tenant_id' => $tenant, 'project_id' => $proyekFo, 'lokasi_id' => $lokB, 'tahap' => '009',
            'unit_type_id' => $tipeId, 'blok' => 'B', 'no_unit' => str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'kode_unit' => $TAG.'-M-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'luas_tanah' => 90, 'luas_lebih_tanah' => 0, 'luas_bangunan' => 36,
            'harga_jual' => 500_000_000, 'status' => 'TERSEDIA',
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    foreach (array_chunk($massal, 200) as $potong) {
        DB::table($FO.'.fo_units')->insert($potong);
    }

    $hBanyak = $pilih(['fo_lokasi' => $lokB, 'fo_tahap' => '009'])($p);
    $ok('daftar melebihi batas dipotong di batasnya', count($hBanyak['rows']) === $batas,
        'terbaca '.count($hBanyak['rows']).' dari '.($batas + 1));
    $ok('  dan pemotongannya DISEBUT', ($hBanyak['terpotong'] ?? null) === true,
        'daftar yang terpotong diam-diam membuat operator mengira unit yang hilang memang tidak ada');
    $ok('  sedangkan daftar yang muat tidak ditandai terpotong', ($pilih([])($p)['terpotong'] ?? null) === false);

    echo "\n=== 7. Kegagalan Front Office tidak menjatuhkan layar ===\n";

    config(['bankloan.fo_database' => 'basis-data-yang-tidak-sah!']);
    $hGagal = $pilih([])($p);
    $ok('nama basis data tidak sah → daftar kosong dengan keterangan',
        $hGagal['rows'] === [] && is_string($hGagal['galat']) && $hGagal['galat'] !== '');
    // Yang diuji BUKAN sekadar "kosong dengan keterangan": kueri yang gagal pun memulangkan itu,
    // sebab dibungkus try/catch. Yang harus dibuktikan adalah namanya DITOLAK SEBELUM masuk SQL —
    // ia menyatu ke kueri sebagai pengenal, bukan nilai berparameter, jadi penyaring itulah
    // satu-satunya yang menahannya. Pesannya yang membedakan keduanya.
    $ok('  ditolak SEBELUM masuk kueri, bukan gagal di dalamnya',
        str_contains((string) $hGagal['galat'], 'belum diatur'),
        'pesan: '.var_export($hGagal['galat'], true)
        .' — pesan "tidak bisa dibaca" berarti namanya sempat masuk ke SQL');
    config(['bankloan.fo_database' => $FO]);

    echo "\n=== 8. Perkabelan layar ===\n";

    $rincian = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Cogs2/Rincian.vue'));
    $impor = file_get_contents(base_path('resources/js/Components/Cogs2/ImporUnit.vue'));
    $ctlSrc = file_get_contents((new ReflectionClass(K::class))->getFileName());

    $ok('daftar unit dikirim sebagai prop LAZY',
        (bool) preg_match("/'unitFo'\s*=> Inertia::lazy\(/", $ctlSrc),
        'satu lokasi bisa berisi ratusan unit, dan hampir setiap kali layar dibuka daftarnya tidak dipakai');
    $ok('  layar meneruskannya ke pemilih', str_contains($rincian, ':unit-fo="unitFo"'));
    $ok('  beserta lokasi & tahap untuk penyaringnya',
        str_contains($rincian, ':fo-lokasi="foLokasi"') && str_contains($rincian, ':fo-tahap="foTahap"'));
    // Diperiksa pada DAFTAR TAB-nya, bukan dengan mencari kata di seluruh berkas: kalimat
    // "Pilih dari Modul Unit" juga muncul di komentar penjelas dan judul bagiannya, sehingga
    // pencarian kata polos tetap ketemu walau tabnya sudah dicabut.
    preg_match("/\[\['unit', '([^']+)'\], \['csv', '([^']+)'\]\]/", $impor, $tab);
    $ok('pemilihnya punya dua jalan masuk (modul Unit & impor CSV)',
        ($tab[1] ?? '') === 'Pilih dari Modul Unit' && ($tab[2] ?? '') === 'Impor CSV',
        'daftar tab: '.($tab[0] ?? 'tidak ketemu'));
    $ok('  daftarnya bisa dicentang', str_contains($impor, 'type="checkbox"') && str_contains($impor, 'centangSemua'));
    $ok('  unit yang sudah ada tidak bisa dicentang ulang',
        str_contains($impor, ':disabled="u.sudah"'),
        'mencentangnya lagi hanya akan dilewati saat masuk draf — mematikannya membuat itu terlihat');
    $ok('  dan hasilnya tetap mengisi DRAF, bukan basis data',
        ! preg_match('/router\.(post|put|patch|delete)/', $impor) && str_contains($impor, "emit('impor'"),
        'simpanan Rincian COGS mengganti seluruh daftar; tulisan langsung akan tertimpa draf lama');

    $tuntas = true;
} catch (\Throwable $e) {
    $gagal++;
    echo "\n  GAGAL (galat tak tertangani) — ".get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    config(['bankloan.fo_database' => $FO]);
    DB::rollBack();

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo ($gagal === 0 && $tuntas) ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
    echo "(data uji di ipro_bo maupun $FO dibatalkan)\n";
}
