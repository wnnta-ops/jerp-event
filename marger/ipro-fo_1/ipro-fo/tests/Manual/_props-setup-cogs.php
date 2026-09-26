<?php

/**
 * PEMBANTU, BUKAN UJI. Dipanggil oleh tests/Manual/uji-setup-cogs-layar.cjs.
 *
 * Menyemai satu Set Up COGS beserta unit fikstur di basis data Front Office, menulis props
 * Inertia SUNGGUHAN dari kedua layarnya ke storage/app/_props-setup-cogs.json, lalu MEMBATALKAN
 * seluruh tulisannya. Kedua basis data berada pada satu sambungan MySQL, jadi satu rollback
 * menutup dua-duanya.
 *
 * Alasannya sama dengan _props-cogs2.php: uji layar yang propsnya ditulis tangan hanya menguji
 * layar terhadap tebakan penulis ujinya, dan ketidakcocokan nama prop antara pengendali dan layar
 * tidak akan pernah terlihat.
 */

use App\Domain\BusinessSupport\Models\BsCogsSetup;
use App\Domain\BusinessSupport\Models\BsCogsSetupComponent;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsSetupCogsController as K;
use Illuminate\Support\Facades\DB;

$user = \App\Models\User::all()->first(fn ($u) => $u->hasRole('SuperAdmin')) ?? \App\Models\User::first();
if (! $user) { echo "TIDAK ADA USER\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);
$tenant = (int) $user->tenant_id;
$FO = (string) config('bankloan.fo_database', '');

$ctl = app(K::class);
$req = function () {
    $r = \Illuminate\Http\Request::create('/uji', 'GET');
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $r->setUserResolver(fn () => auth()->user());

    return $r;
};
$props = function ($resp) use ($req) {
    return json_decode((string) $resp->toResponse($req())->getContent(), true)['props'] ?? [];
};

DB::beginTransaction();

try {
    $proyekFo = (int) DB::table($FO.'.projects')->value('id');
    $lokId = (int) DB::table($FO.'.fo_lokasi')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $proyekFo, 'kode' => 'LZLY', 'nama' => 'LOKASI UJI LAYAR',
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $tipeId = (int) DB::table($FO.'.fo_unit_types')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $proyekFo, 'code' => 'TZLY', 'name' => 'TIPE UJI',
        'luas_tanah' => 100, 'luas_bangunan' => 45, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Σ LT = 17.150 supaya rekonsiliasinya cocok; satu unit BATAL untuk membuktikan ia tidak ikut.
    foreach ([
        ['A-01', 5_000.00, 200.00, 'TERSEDIA'],
        ['A-02', 6_000.00, 300.00, 'TERJUAL'],
        ['A-03', 4_150.25, 150.50, 'BOOKING'],
        ['A-04', 1_999.75,  99.50, 'LUNAS'],
        ['A-99', 9_999.00, 999.00, 'BATAL'],
    ] as $i => [$kode, $lt, $lb, $status]) {
        DB::table($FO.'.fo_units')->insert([
            'tenant_id' => $tenant, 'project_id' => $proyekFo, 'lokasi_id' => $lokId, 'tahap' => '01',
            'unit_type_id' => $tipeId, 'blok' => 'A', 'no_unit' => substr($kode, 2),
            'kode_unit' => 'ZLY-'.$kode, 'luas_tanah' => $lt, 'luas_bangunan' => $lb,
            'luas_lebih_tanah' => 0, 'harga_jual' => 500_000_000, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $s = BsCogsSetup::create([
        'tenant_id' => $tenant, 'lokasi_id' => $lokId, 'lokasi_kode' => 'LZLY',
        'lokasi_nama' => 'LOKASI UJI LAYAR', 'tahap' => '01',
        'luas_lahan' => 31_933, 'saleable' => 17_150, 'rth' => 9_579.9,
        'jumlah_unit' => 199, 'dasar_tanah' => 'saleable', 'termasuk_lebih_tanah' => false,
        'catatan' => 'Set Up uji layar',
    ]);
    // Keempat pos lampiran + satu pos berdasar BANGUNAN, supaya layar memuat kedua dasar pembagi.
    foreach ([
        ['LAC Tanah', 'tanah', 19_300_000_000],
        ['Infrastruktur', 'tanah', 10_255_943_066.22],
        ['Undertable', 'tanah', 2_739_145_000],
        ['License Permit', 'tanah', 1_222_662_296],
        ['Housing', 'bangunan', 750_000_000],
    ] as $i => [$nama, $dasar, $nilai]) {
        BsCogsSetupComponent::create([
            'tenant_id' => $tenant, 'setup_id' => $s->id,
            'nama' => $nama, 'dasar' => $dasar, 'nilai' => $nilai, 'urut' => $i + 1,
        ]);
    }

    $keluar = [
        'Index' => $props($ctl->index($req())),
        'Show'  => $props($ctl->show($req(), $s)),
    ];

    $tujuan = storage_path('app/_props-setup-cogs.json');
    file_put_contents($tujuan, json_encode($keluar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo 'PROPS DITULIS: '.$tujuan.' ('.count($keluar)." layar)\n";
} catch (\Throwable $e) {
    echo 'GAGAL: '.get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
}
