<?php

/**
 * PEMBANTU, BUKAN UJI. Dipanggil oleh tests/Manual/uji-cogs-baru-layar.cjs.
 *
 * Menyemai satu periode COGS Baru yang lengkap, lalu MENULIS PROPS INERTIA SUNGGUHAN dari
 * keempat layarnya ke storage/app/_props-cogs2.json, lalu MEMBATALKAN seluruh tulisannya.
 *
 * Alasannya: uji layar yang propsnya ditulis tangan hanya menguji layar terhadap tebakan penulis
 * ujinya. Begitu pengendali mengganti nama satu prop, layarnya menampilkan kolom kosong dan
 * ujinya tetap hijau. Dengan props asli, ketidakcocokan itu ketahuan — dan itulah satu-satunya
 * kelas kesalahan yang tidak tertangkap oleh uji sisi server maupun oleh vue-tsc (beberapa prop
 * di layar ini memang bertipe `any` karena bentuknya ditentukan kalkulator).
 */

use App\Domain\BusinessSupport\Models\BsCogs2Actual;
use App\Domain\BusinessSupport\Models\BsCogs2HousingTipe;
use App\Domain\BusinessSupport\Models\BsCogs2Kategori;
use App\Domain\BusinessSupport\Models\BsCogs2Periode;
use App\Domain\BusinessSupport\Models\BsCogs2Unit;
use App\Http\Controllers\Web\BusinessSupport\Cogs\Cogs2Controller as K;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::all()->first(fn ($u) => $u->hasRole('SuperAdmin')) ?? \App\Models\User::first();
if (! $user) { echo "TIDAK ADA USER\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);
$tenant = (int) $user->tenant_id;

$ctl = app(K::class);
$props = function ($resp) {
    $r = \Illuminate\Http\Request::create('/uji', 'GET');
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());

    return json_decode((string) $resp->toResponse($r)->getContent(), true)['props'] ?? [];
};
$req = function () {
    $r = \Illuminate\Http\Request::create('/uji', 'GET');
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $r->setUserResolver(fn () => auth()->user());

    return $r;
};

DB::beginTransaction();

try {
    $p = BsCogs2Periode::create([
        'tenant_id' => $tenant, 'lokasi_id' => 990909, 'lokasi_kode' => 'JRK3',
        'lokasi_nama' => 'JAVA RESIDENCE KRIAN CLUSTER 3', 'tahap' => '001 & 002',
        'luas_lahan' => 31_933, 'saleable' => 17_150, 'rth' => 9_579.9,
        'luas_bangunan' => 500, 'jumlah_unit' => 199, 'catatan' => 'Periode uji layar',
    ]);

    // Dua tipe supaya blok Housing punya lebih dari satu baris turunan.
    foreach ([['CICA', 36], ['CAMAR', 40]] as $i => [$nama, $lb]) {
        BsCogs2HousingTipe::create(['tenant_id' => $tenant, 'periode_id' => $p->id,
            'nama' => $nama, 'luas_bangunan' => $lb, 'urut' => $i + 1]);
    }

    BsCogs2Kategori::create(['tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => 'tanah',
        'lokasi' => 'JRK3', 'tahap' => '001 & 002', 'luas_tanah' => 31_933,
        'harga_rata2' => 506_685.873548, 'nominal' => round(31_933 * 506_685.873548, 2), 'urut' => 1]);
    foreach ([['CICA', 'SPK 028', 7_106_561_179.40], ['CAMAR', 'SPK 037', 7_358_067_000.00]] as $i => [$kat, $spk, $nom]) {
        BsCogs2Kategori::create(['tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => 'housing',
            'lokasi' => 'JRK3', 'tahap' => '001 & 002', 'kategori' => $kat, 'no_spk' => $spk,
            'nominal' => $nom, 'urut' => $i + 1]);
    }
    foreach ([['URUGAN', 3_769_246_000], ['INFRASTRUKTUR', 5_266_629_231]] as $i => [$kat, $nom]) {
        BsCogs2Kategori::create(['tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => 'infra',
            'lokasi' => 'JRK3', 'tahap' => '002', 'kategori' => $kat, 'nominal' => $nom, 'urut' => $i + 1]);
    }
    foreach ([['SERTIFIKASI', 1_891_470_776], ['PERIZINAN', 1_063_804_724]] as $i => [$kat, $nom]) {
        BsCogs2Kategori::create(['tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => 'license',
            'lokasi' => 'JRK3', 'tahap' => '002', 'kategori' => $kat, 'nominal' => $nom, 'urut' => $i + 1]);
    }

    foreach ([
        ['tanah', null, null, 'CB.BKK/UJI/001', 12_148_288_288.29, 0],
        ['housing', 'CICA', 'SPK 028', 'CB.BKK/UJI/002', 7_106_561_179.40, 0],
        ['housing', 'CAMAR', 'SPK 037', 'CB.BKK/UJI/003', 7_500_000_000.00, 100_000_000],
        ['infra', 'URUGAN', 'SPK INF/001', 'CB.BKK/UJI/004', 11_319_837_370.68, 0],
        ['license', 'SERTIFIKASI', null, 'CB.BKK/UJI/005', 3_750_678_203.00, 0],
    ] as $i => [$komp, $kat, $spk, $jur, $tambah, $kurang]) {
        BsCogs2Actual::create(['tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => $komp,
            'lokasi' => 'JRK3', 'tahap' => '001 & 002', 'kategori' => $kat, 'no_spk' => $spk,
            'no_jurnal' => $jur, 'keterangan' => 'Realisasi uji layar',
            'penambahan' => $tambah, 'pengurangan' => $kurang, 'urut' => $i + 1]);
    }

    // Enam unit yang sengaja mencakup SELURUH keadaan: terkunci, booked-realisasi, booked tanpa
    // pilihan, belum booked, revenue terisi, dan revenue kosong. Layar harus benar di semuanya.
    $unit = [
        ['ZL-001', 'CICA',  84, 36, 'sudah', 'standard',  900_000_000],
        ['ZL-002', 'CICA',  84, 36, 'sudah', 'realisasi', 950_000_000],
        ['ZL-003', 'CICA',  84, 36, 'sudah', 'belum',     null],
        ['ZL-004', 'CAMAR', 90, 40, 'belum', 'belum',     null],
        ['ZL-005', 'CAMAR', 90, 40, 'belum', 'belum',     800_000_000],
        ['ZL-006', 'CAMAR', 90, 40, 'belum', 'belum',     null],
    ];
    foreach ($unit as $i => [$kode, $tipe, $lt, $lb, $st, $cost, $rev]) {
        BsCogs2Unit::create(['tenant_id' => $tenant, 'periode_id' => $p->id,
            'no_spr' => 'SPR-'.($i + 1), 'nama_customer' => 'PELANGGAN UJI '.($i + 1),
            'unit_rumah' => $kode, 'lokasi' => 'JRK3', 'tahap' => '001 & 002',
            'tipe' => $tipe, 'lt' => $lt, 'lb' => $lb,
            'status' => $st, 'costing' => $cost, 'booked_revenue' => $rev, 'urut' => $i + 1]);
    }

    $keluar = [
        'Index'       => $props($ctl->index($req())),
        'Perhitungan' => $props($ctl->perhitungan($req(), $p)),
        'Rincian'     => $props($ctl->rincian($req(), $p)),
    ];
    foreach (['tanah', 'housing', 'infra', 'license'] as $k) {
        $keluar['Kategori-'.$k] = $props($ctl->kategori($req(), $p, $k));
        $keluar['Actual-'.$k] = $props($ctl->actual($req(), $p, $k));
    }

    $tujuan = storage_path('app/_props-cogs2.json');
    file_put_contents($tujuan, json_encode($keluar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo 'PROPS DITULIS: '.$tujuan.' ('.count($keluar)." layar)\n";
} catch (\Throwable $e) {
    echo 'GAGAL: '.get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
}
