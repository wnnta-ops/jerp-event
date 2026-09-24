<?php

/**
 * Set Up COGS — Perhitungan Lahan → budget per unit menurut luas tanah & luas bangunan.
 *
 * UJI UTAMANYA: ANGKA LAMPIRAN HARUS KELUAR PERSIS.
 *
 * Seluruh blok "Perhitungan Lahan" dan keempat tarif per m2 pada lampiran dimasukkan apa adanya,
 * lalu dituntut keluar sampai senannya:
 *
 *      Luas Lahan 31.933 · Saleable 17.150 (53,706%) · RTH 9.579,9 (30%) · Jalan 5.203,1 (16,294%)
 *      LAC Tanah      19.300.000.000     ÷ Saleable = 1.125.364,43 /m2
 *      Infrastruktur  10.255.943.066,22  ÷ Saleable =   598.014,17 /m2
 *      Undertable      2.739.145.000     ÷ Saleable =   159.716,91 /m2
 *      License Permit  1.222.662.296     ÷ Saleable =    71.292,26 /m2
 *      TOTAL COGS     33.517.750.362,22
 *      COGS GROSS      1.049.627,36 /m2  (÷ Luas Lahan — penyebut BERBEDA)
 *      COGS Saleable   1.954.387,78 /m2  (÷ Saleable)
 *
 * Yang juga dijaga, berurut dari yang paling mahal:
 *
 *  1. DUA PENYEBUT TIDAK BOLEH TERTUKAR. COGS GROSS dibagi Luas Lahan, COGS Saleable dibagi
 *     Saleable — selisihnya 1,86 kali. Tertukar sedikit saja menghasilkan angka yang tetap
 *     terlihat masuk akal dan salah 86%.
 *  2. LUAS BANGUNAN BENAR-BENAR DIPAKAI. Pos berdasar 'bangunan' dibagi luas bangunan unit, bukan
 *     luas tanah. Inilah yang diminta user ("sesuai luas tanah DAN luas bangunan per masing-masing
 *     unit"), dan lampiran sendiri tidak memuat satu pun pos bangunan.
 *  3. LUAS DIBACA PER UNIT, BUKAN PER TIPE. Dua unit bertipe sama dengan luas berbeda harus
 *     menerima budget berbeda. Satu-satunya sumber yang bisa begitu adalah jerp.fo_units;
 *     unit_types.lt/lb hanya per jenis dan bertipe integer (105,5 → 106 diam-diam).
 *  4. Σ BAGIAN = NILAI POS, PERSIS, saat pembaginya Σ luas unit — bagian terakhir menyerap sisa
 *     pembulatan. Saat pembaginya Saleable yang diketik, selisihnya TIDAK ditambal melainkan
 *     DILAPORKAN: menambalnya menyembunyikan bahwa data luasnya belum cocok.
 *  5. POS TANPA PEMBAGI TIDAK DIBAGI RATA dan tidak dibuang — nilainya tetap masuk TOTAL COGS
 *     dengan alasan tertulis.
 *  6. SATU CAKUPAN SATU SET-UP (kunci unik), dan `tahap` disimpan '' bukan NULL — kunci unik
 *     tidak menahan NULL yang kembar.
 *  7. Unit BATAL tidak ikut dibebani; status lain ikut semua (lampiran membagi ke seluruh unit,
 *     bukan hanya yang terjual).
 *
 * Seluruh tulisan — termasuk unit fikstur di basis data FO — dibungkus transaksi lalu DIBATALKAN.
 * Keduanya berada pada satu server MySQL dan satu sambungan, jadi satu rollback menutup dua-duanya.
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-setup-cogs.php';"
 */

use App\Domain\BusinessSupport\Models\BsCogsSetup;
use App\Domain\BusinessSupport\Models\BsCogsSetupComponent;
use App\Domain\BusinessSupport\Services\CogsSetupKalkulator;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsSetupCogsController as K;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$lulus = 0;
$gagal = 0;
$tuntas = false;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
/** Pembanding uang/luas: dua angka dianggap sama bila selisihnya di bawah setengah sen. */
$sama = fn ($a, $b) => $a !== null && abs((float) $a - (float) $b) < 0.005;

$user = \App\Models\User::all()->first(fn ($u) => $u->hasRole('SuperAdmin')) ?? \App\Models\User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);
$tenant = (int) $user->tenant_id;

$FO = (string) config('bankloan.fo_database', '');
$TAG = 'ZSC'.strtoupper(substr((string) getmypid(), -4));

$props = function (BsCogsSetup $s) {
    $r = \Illuminate\Http\Request::create('/uji', 'GET');
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $r->setUserResolver(fn () => auth()->user());

    return json_decode((string) app(K::class)->show($r, $s->fresh())->toResponse($r)->getContent(), true)['props'] ?? [];
};

DB::beginTransaction();

try {
    $ok('prasyarat: tabel Set Up COGS ada', Schema::hasTable('bs_cogs_setup') && Schema::hasTable('bs_cogs_setup_components'),
        'jalankan migrasi 2026_09_26_100000_create_bs_cogs_setup.php');
    $ok('prasyarat: basis data FO dikenal', $FO !== '' && preg_match('/^[A-Za-z0-9_]+$/', $FO) === 1, 'config bankloan.fo_database = '.$FO);

    // ── Lokasi + unit fikstur di FO ────────────────────────────────────────────────
    // Sengaja ditanam di jerp.fo_units, BUKAN di units BO: di situlah luas per unit
    // sesungguhnya hidup, dan uji yang menanamnya di tempat lain tidak menguji jalur yang dipakai.
    $proyekFo = (int) DB::table($FO.'.projects')->value('id');
    $lokId = (int) DB::table($FO.'.fo_lokasi')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $proyekFo, 'kode' => 'L'.$TAG, 'nama' => 'LOKASI UJI '.$TAG,
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $tipeId = (int) DB::table($FO.'.fo_unit_types')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $proyekFo, 'code' => 'T'.$TAG, 'name' => 'TIPE UJI',
        'luas_tanah' => 100, 'luas_bangunan' => 45, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Empat unit. LT sengaja dibuat berjumlah TEPAT 17.150 supaya angka lampiran bisa diuji
    // pada kedua jalur pembagi. Dua unit pertama BERTIPE SAMA tetapi berluas BERBEDA — itulah
    // yang membuktikan luasnya dibaca per unit, bukan per tipe.
    $unitUji = [
        ['A-01', 5_000.00, 200.00, 0.00,   'TERSEDIA'],
        ['A-02', 6_000.00, 300.00, 0.00,   'TERJUAL'],
        ['A-03', 4_150.25, 150.50, 0.00,   'BOOKING'],
        ['A-04', 1_999.75,  99.50, 0.00,   'LUNAS'],
        // BATAL — tidak boleh ikut dibebani, jadi luasnya tidak masuk hitungan mana pun.
        ['A-99', 9_999.00, 999.00, 0.00,   'BATAL'],
    ];
    foreach ($unitUji as $i => [$kode, $lt, $lb, $lebih, $status]) {
        DB::table($FO.'.fo_units')->insert([
            'tenant_id' => $tenant, 'project_id' => $proyekFo, 'lokasi_id' => $lokId, 'tahap' => '01',
            'unit_type_id' => $tipeId, 'blok' => 'A', 'no_unit' => substr($kode, 2),
            'kode_unit' => $TAG.'-'.$kode,
            'luas_tanah' => $lt, 'luas_bangunan' => $lb, 'luas_lebih_tanah' => $lebih,
            'harga_jual' => 500_000_000, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $SALEABLE = 17_150.00;   // = 5.000 + 6.000 + 4.150,25 + 1.999,75
    $LB_TOTAL = 750.00;      // = 200 + 300 + 150,5 + 99,5

    // ── Set-up + pos biaya, persis lampiran ────────────────────────────────────────
    $setup = BsCogsSetup::create([
        'tenant_id' => $tenant, 'lokasi_id' => $lokId, 'lokasi_kode' => 'L'.$TAG,
        'lokasi_nama' => 'LOKASI UJI '.$TAG, 'tahap' => '01',
        'luas_lahan' => 31_933, 'saleable' => $SALEABLE, 'rth' => 9_579.9,
        'jumlah_unit' => 199, 'dasar_tanah' => 'saleable', 'termasuk_lebih_tanah' => false,
    ]);
    $POS = [
        ['LAC Tanah', 'tanah', 19_300_000_000],
        ['Infrastruktur', 'tanah', 10_255_943_066.22],
        ['Undertable', 'tanah', 2_739_145_000],
        ['License Permit', 'tanah', 1_222_662_296],
    ];
    foreach ($POS as $i => [$nama, $dasar, $nilai]) {
        BsCogsSetupComponent::create([
            'tenant_id' => $tenant, 'setup_id' => $setup->id,
            'nama' => $nama, 'dasar' => $dasar, 'nilai' => $nilai, 'urut' => $i + 1,
        ]);
    }

    echo "\n=== 1. Perhitungan Lahan ===\n";

    $d = $props($setup);
    $h = $d['hitung'] ?? [];
    $L = $h['lahan'] ?? [];

    $ok('Luas Lahan 31.933', $sama($L['luas_lahan'] ?? null, 31_933));
    $ok('Saleable 17.150 = 53,706%', $sama($L['saleable'] ?? null, 17_150)
        && abs((float) ($L['persen_saleable'] ?? 0) - 0.53706197350703) < 0.0000001,
        'persen='.($L['persen_saleable'] ?? 'tidak ada'));
    $ok('RTH 9.579,9 = 30,000%', $sama($L['rth'] ?? null, 9_579.9)
        && abs((float) ($L['persen_rth'] ?? 0) - 0.3) < 0.0000001);
    $ok('Jalan 5.203,1 DITURUNKAN (Lahan − Saleable − RTH)', $sama($L['jalan'] ?? null, 5_203.1),
        'jalan='.($L['jalan'] ?? 'tidak ada'));
    $ok('  persen Jalan 16,294%', abs((float) ($L['persen_jalan'] ?? 0) - 0.16293802649297) < 0.0000001);
    // Jalan tidak boleh punya kolomnya sendiri: angka turunan yang disimpan bisa berbeda dari rumusnya.
    $ok('  Jalan TIDAK disimpan sebagai kolom', ! Schema::hasColumn('bs_cogs_setup', 'jalan'),
        'kolom turunan yang disimpan akan menyimpang dari rumusnya tanpa ada yang menolak');

    echo "\n=== 2. Tarif per m2 & total (angka lampiran) ===\n";

    $K4 = $h['komponen'] ?? [];
    $ok('empat pos biaya terbaca', count($K4) === 4);
    $harap = [
        ['LAC Tanah', 1_125_364.4314868804],
        ['Infrastruktur', 598_014.1729575802],
        ['Undertable', 159_716.90962099125],
        ['License Permit', 71_292.26215743441],
    ];
    foreach ($harap as $i => [$nama, $tarif]) {
        $ok('  '.$nama.' ÷ Saleable = '.number_format($tarif, 2, ',', '.').' /m²',
            ($K4[$i]['nama'] ?? '') === $nama && abs((float) ($K4[$i]['tarif'] ?? 0) - $tarif) < 0.005,
            'tarif='.($K4[$i]['tarif'] ?? 'tidak ada'));
        $ok('    pembaginya Saleable, bukan Luas Lahan', $sama($K4[$i]['pembagi'] ?? null, 17_150),
            'tertukar dengan Luas Lahan menghasilkan angka 1,86x lebih kecil yang tetap terlihat wajar');
    }

    $T = $h['total'] ?? [];
    $ok('TOTAL COGS 33.517.750.362,22', $sama($T['total_cogs'] ?? null, 33_517_750_362.22),
        'total='.($T['total_cogs'] ?? 'tidak ada'));
    $ok('COGS GROSS 1.049.627,36 (÷ Luas Lahan)', abs((float) ($T['cogs_gross'] ?? 0) - 1_049_627.3560962408) < 0.005,
        'gross='.($T['cogs_gross'] ?? 'tidak ada'));
    $ok('COGS Saleable 1.954.387,78 (÷ Saleable)', abs((float) ($T['cogs_saleable'] ?? 0) - 1_954_387.7762228134) < 0.005,
        'saleable='.($T['cogs_saleable'] ?? 'tidak ada'));
    // Inilah pemeriksaan yang menangkap dua penyebut tertukar: keduanya HARUS berbeda.
    $ok('  COGS GROSS dan COGS Saleable BUKAN angka yang sama',
        abs((float) ($T['cogs_gross'] ?? 0) - (float) ($T['cogs_saleable'] ?? 0)) > 1_000,
        'dua penyebut berbeda (31.933 vs 17.150) — kalau sama, salah satunya memakai pembagi yang salah');
    $ok('  Σ tarif pos tanah = COGS Saleable (identitas lampiran)',
        abs((float) ($T['tarif_tanah'] ?? 0) - (float) ($T['cogs_saleable'] ?? 0)) < 0.005);

    echo "\n=== 3. Budget per unit — luas tanah ===\n";

    $U = $h['unit'] ?? [];
    $ok('unit BATAL tidak ikut dibebani', count($U) === 4,
        'terbaca '.count($U).' unit; lampiran membagi ke seluruh unit KECUALI yang batal');
    $ok('  luas dibaca PER UNIT, bukan per tipe',
        $sama($U[0]['lt'] ?? null, 5_000) && $sama($U[1]['lt'] ?? null, 6_000),
        'dua unit bertipe sama berluas berbeda — kalau keduanya sama, luasnya diambil dari unit_types');
    $ok('  Σ luas tanah unit = 17.150', $sama($h['unitTotal']['lt'] ?? null, 17_150));

    // Budget satu unit = Σ bagian TIAP POS, masing-masing dibulatkan sendiri. Menghitungnya
    // sebagai luas × Σtarif lalu dibulatkan SEKALI memberi angka yang beda sesen — jadi harapannya
    // diturunkan dengan cara yang sama persis dengan yang dipakai layar, bukan disederhanakan.
    $budget = function (float $luas) use ($POS, $SALEABLE) {
        $t = 0.0;
        foreach ($POS as [$n, $d, $nilai]) { $t = round($t + round($luas * ((float) $nilai / $SALEABLE), 2), 2); }

        return $t;
    };
    foreach ([[0, 'A-01', 5_000.0], [1, 'A-02', 6_000.0], [2, 'A-03', 4_150.25]] as [$i, $kode, $luas]) {
        $ok('  '.$kode.' ('.number_format($luas, 2, ',', '.').' m²) menerima '.number_format($budget($luas), 2, ',', '.'),
            $sama($U[$i]['total'] ?? null, $budget($luas)),
            'total='.($U[$i]['total'] ?? 'tidak ada'));
    }
    // A-04 adalah unit TERAKHIR yang punya luas, dan pada fikstur ini Saleable yang diketik
    // kebetulan sama persis dengan Σ luas tanah unit — jadi ia menyerap sisa pembulatan tiap pos.
    // Bagiannya karena itu SENGAJA bukan luas × tarif, melainkan nilai pos dikurangi bagian yang
    // sudah dibagikan. Menuntutnya sama dengan luas × tarif berarti menuntut jumlah seluruh bagian
    // meleset dari TOTAL COGS.
    $sisaA04 = 0.0;
    foreach ($POS as [$n, $d, $nilai]) {
        $sudah = 0.0;
        foreach ([5_000.0, 6_000.0, 4_150.25] as $luas) { $sudah = round($sudah + round($luas * ((float) $nilai / $SALEABLE), 2), 2); }
        $sisaA04 = round($sisaA04 + round((float) $nilai - $sudah, 2), 2);
    }
    $ok('  A-04 (1.999,75 m², unit terakhir) menerima sisanya: '.number_format($sisaA04, 2, ',', '.'),
        $sama($U[3]['total'] ?? null, $sisaA04), 'total='.($U[3]['total'] ?? 'tidak ada'));
    $ok('  Σ budget seluruh unit = TOTAL COGS, PERSIS',
        $sama($h['unitTotal']['total'] ?? null, 33_517_750_362.22),
        'Σ='.($h['unitTotal']['total'] ?? 'tidak ada')
        .' — sisa pembulatan tidak diserap, jumlah per unit tidak akan pernah cocok dengan TOTAL COGS');
    // Sebanding dengan luasnya: A-02 (6.000) harus 1,2x A-01 (5.000), dalam batas pembulatan sen.
    $ok('  budget sebanding dengan luas tanah (A-02 = 1,2 × A-01)',
        abs((float) ($U[1]['total'] ?? 0) - 1.2 * (float) ($U[0]['total'] ?? 0)) < 0.05,
        'kalau tidak sebanding, pembaginya bukan luas tanah');

    echo "\n=== 4. Pembagi Saleable: selisih DILAPORKAN, tidak ditambal ===\n";

    // Pada fikstur ini Σ LT sengaja SAMA dengan Saleable, jadi selisihnya nol.
    $R = $h['rekon'] ?? [];
    $ok('rekonsiliasi cocok saat Σ LT = Saleable', ($R['cocok'] ?? false) === true && $sama($R['selisih_m2'] ?? null, 0));
    $ok('  jumlah unit yang diketik diadu dengan yang terbaca',
        ($R['jumlah_unit_input'] ?? null) === 199 && ($R['jumlah_unit_baca'] ?? null) === 4);

    // Sekarang Saleable digeser 1.000 m2 — lampiran memakai angka yang DIKETIK, jadi tarifnya
    // ikut bergeser dan Σ budget unit TIDAK lagi sama dengan TOTAL COGS. Itu harus TERLIHAT.
    $setup->update(['saleable' => 18_150]);
    $d2 = $props($setup);
    $R2 = $d2['hitung']['rekon'] ?? [];
    $ok('Saleable digeser → ketidakcocokan dilaporkan', ($R2['cocok'] ?? true) === false
        && $sama($R2['selisih_m2'] ?? null, 1_000));
    $ok('  selisih rupiahnya bukan nol dan disebut angkanya',
        abs((float) ($R2['selisih_rp'] ?? 0)) > 1_000_000,
        'selisih='.($R2['selisih_rp'] ?? 'tidak ada').' — menambalnya diam-diam menyembunyikan bahwa data luas belum cocok');
    $setup->update(['saleable' => $SALEABLE]);

    echo "\n=== 5. Pembagi Σ luas unit: Σ bagian = nilai, PERSIS ===\n";

    // Nilai yang sengaja tidak habis dibagi, dan luas unit yang berkoma.
    BsCogsSetupComponent::where('setup_id', $setup->id)->delete();
    BsCogsSetupComponent::create([
        'tenant_id' => $tenant, 'setup_id' => $setup->id,
        'nama' => 'Pos Bersisa', 'dasar' => 'tanah', 'nilai' => 1_000_000_000.01, 'urut' => 1,
    ]);
    // Saleable sengaja digeser ke 20.000 supaya BERBEDA dari Σ luas tanah unit (17.150). Tanpa
    // perbedaan itu kedua pilihan pembagi menghasilkan angka yang sama, dan uji ini tidak
    // membuktikan bahwa pilihannya benar-benar dipakai.
    $setup->update(['dasar_tanah' => 'unit', 'saleable' => 20_000]);

    $d3 = $props($setup);
    $h3 = $d3['hitung'] ?? [];
    $ok('pembaginya Σ luas tanah unit (17.150), BUKAN Saleable yang diketik (20.000)',
        $sama($h3['komponen'][0]['pembagi'] ?? null, 17_150)
        && ($h3['pembagi']['tanah']['sumber'] ?? null) === 'unit',
        'pembagi='.($h3['komponen'][0]['pembagi'] ?? 'tidak ada'));
    $ok('Σ bagian unit = nilai pos, PERSIS', $sama($h3['komponen'][0]['terbagi'] ?? null, 1_000_000_000.01),
        'terbagi='.($h3['komponen'][0]['terbagi'] ?? 'tidak ada')
        .' — sisa pembulatan tidak dibebankan ke unit terakhir');
    $ok('  selisihnya nol', $sama($h3['komponen'][0]['selisih'] ?? null, 0));
    // Penyerapan sisa diuji dengan kasus yang PASTI bersisa: tiga luas sama persis, nilai yang
    // tidak habis dibagi tiga. 1.000 ÷ 3 = 333,333… → dibulatkan sendiri-sendiri jumlahnya 999,99.
    // Angka yang kebetulan habis dibagi membuat uji ini tidak membuktikan apa pun — versi pertama
    // uji ini memang begitu, dan mutasinya lolos.
    $tiga = app(CogsSetupKalkulator::class)->hitung(
        ['luas_lahan' => 300, 'saleable' => 300, 'rth' => 0, 'jumlah_unit' => null,
            'dasar_tanah' => 'unit', 'termasuk_lebih_tanah' => false],
        [['nama' => 'Bersisa', 'dasar' => 'tanah', 'nilai' => 1_000.00]],
        array_map(fn ($k) => ['kode' => $k, 'tipe' => null, 'blok' => null, 'no_unit' => null,
            'luas_tanah' => 100, 'luas_bangunan' => 0, 'luas_lebih_tanah' => 0], ['U1', 'U2', 'U3']),
    );
    $bagian = array_map(fn ($u) => $u['bagian'][0], $tiga['unit']);
    $ok('  unit terakhir menyerap sisa: 333,33 + 333,33 + 333,34', $bagian === [333.33, 333.33, 333.34],
        'bagian='.json_encode($bagian).' — dibulatkan sendiri-sendiri jumlahnya 999,99, meleset sesen dari 1.000,00');
    $ok('  jumlahnya tepat 1.000,00', $sama(array_sum($bagian), 1_000.00));

    echo "\n=== 6. Luas BANGUNAN benar-benar dipakai ===\n";

    BsCogsSetupComponent::where('setup_id', $setup->id)->delete();
    BsCogsSetupComponent::create(['tenant_id' => $tenant, 'setup_id' => $setup->id,
        'nama' => 'Housing', 'dasar' => 'bangunan', 'nilai' => 750_000_000, 'urut' => 1]);

    $d4 = $props($setup);
    $h4 = $d4['hitung'] ?? [];
    $ok('pembaginya Σ luas BANGUNAN (750 m²), bukan luas tanah',
        $sama($h4['komponen'][0]['pembagi'] ?? null, $LB_TOTAL),
        'pembagi='.($h4['komponen'][0]['pembagi'] ?? 'tidak ada').' — kalau 17.150, ia memakai luas tanah');
    $ok('  tarif = Rp 1.000.000 per m² bangunan', $sama($h4['komponen'][0]['tarif'] ?? null, 1_000_000));
    $ok('  A-01 (LB 200 m²) menerima Rp 200.000.000', $sama($h4['unit'][0]['bagian'][0] ?? null, 200_000_000),
        'kalau memakai luas tanah, A-01 akan menerima angka yang jauh berbeda');
    $ok('  A-02 (LB 300 m²) menerima Rp 300.000.000', $sama($h4['unit'][1]['bagian'][0] ?? null, 300_000_000));
    $ok('  Σ bagian = nilai pos', $sama($h4['komponen'][0]['terbagi'] ?? null, 750_000_000));

    echo "\n=== 7. Pos tanpa pembagi tidak dikarang ===\n";

    // Set-up pada lokasi yang tidak punya unit sama sekali.
    $kosong = BsCogsSetup::create([
        'tenant_id' => $tenant, 'lokasi_id' => $lokId, 'tahap' => '99',
        'luas_lahan' => 1_000, 'saleable' => 0, 'rth' => 0,
    ]);
    BsCogsSetupComponent::create(['tenant_id' => $tenant, 'setup_id' => $kosong->id,
        'nama' => 'Tanpa Dasar', 'dasar' => 'tanah', 'nilai' => 500_000_000, 'urut' => 1]);

    $d5 = $props($kosong);
    $h5 = $d5['hitung'] ?? [];
    $ok('pos tanpa pembagi TIDAK dibagi rata',
        array_key_exists('tarif', $h5['komponen'][0] ?? []) && $h5['komponen'][0]['tarif'] === null
        && $sama($h5['komponen'][0]['terbagi'] ?? null, 0),
        'membagi rata menghasilkan budget per unit yang terlihat wajar dan salah');
    $ok('  alasannya tertulis', is_string($h5['komponen'][0]['alasan'] ?? null)
        && ($h5['komponen'][0]['alasan'] ?? '') !== '',
        'alasan: '.var_export($h5['komponen'][0]['alasan'] ?? null, true));
    $ok('  nilainya TETAP masuk TOTAL COGS', $sama($h5['hitung']['total']['total_cogs'] ?? ($h5['total']['total_cogs'] ?? null), 500_000_000),
        'membuangnya menghilangkan setengah miliar dari layar tanpa tanda');

    // Kasus yang jauh lebih berbahaya daripada set-up tanpa unit: pembaginya nol PADAHAL unitnya
    // ada. Di situlah "dibagi rata" punya kesempatan terjadi — dan hasilnya akan terlihat seperti
    // budget yang wajar. Set-up tanpa unit tidak pernah bisa membuktikan ini, sebab tidak ada
    // siapa pun untuk dibagi.
    BsCogsSetupComponent::where('setup_id', $setup->id)->delete();
    BsCogsSetupComponent::create(['tenant_id' => $tenant, 'setup_id' => $setup->id,
        'nama' => 'Pembagi Nol', 'dasar' => 'tanah', 'nilai' => 800_000_000, 'urut' => 1]);
    $setup->update(['dasar_tanah' => 'saleable', 'saleable' => 0]);

    $d6 = $props($setup);
    $h6 = $d6['hitung'] ?? [];
    $ok('pembagi nol PADAHAL ada 4 unit: tetap tidak dibagi rata',
        count($h6['unit'] ?? []) === 4
        && array_sum(array_map(fn ($u) => (float) ($u['bagian'][0] ?? 0), $h6['unit'] ?? [])) === 0.0,
        'bagian='.json_encode(array_map(fn ($u) => $u['bagian'][0] ?? null, $h6['unit'] ?? []))
        .' — dibagi rata memberi tiap unit Rp 200.000.000 yang terlihat sepenuhnya wajar');
    $ok('  alasannya tertulis dan nilainya tetap di TOTAL COGS',
        is_string($h6['komponen'][0]['alasan'] ?? null) && $sama($h6['total']['total_cogs'] ?? null, 800_000_000));
    $setup->update(['saleable' => $SALEABLE]);

    echo "\n=== 8. Satu cakupan satu Set Up ===\n";

    $ctl = app(K::class);
    $post = function (array $data) {
        $r = \Illuminate\Http\Request::create('/x', 'POST', $data);
        $r->setUserResolver(fn () => auth()->user());

        return $r;
    };
    $resp = $ctl->store($post(['lokasi_id' => $lokId, 'tahap' => '01']));
    $ok('cakupan yang sudah ada ditolak', $resp->getSession()?->get('errors') !== null
        || count(BsCogsSetup::where('lokasi_id', $lokId)->where('tahap', '01')->get()) === 1,
        'dua set-up untuk cakupan yang sama menghasilkan dua budget per unit yang berbeda');

    // '' bukan NULL: kunci unik MySQL tidak menahan NULL yang kembar.
    $ctl->store($post(['lokasi_id' => $lokId, 'tahap' => null]));
    $seluruh = BsCogsSetup::where('lokasi_id', $lokId)->where('tahap', '')->get();
    $ok('tahap kosong disimpan "" (bukan NULL)', $seluruh->count() === 1,
        'kunci unik (tenant_id, lokasi_id, tahap) tidak menahan NULL yang kembar');
    $ok('  dan pos biaya bawaan ikut dibuat (empat baris lampiran)',
        BsCogsSetupComponent::where('setup_id', $seluruh->first()?->id)->count() === 4);
    $ok('  urutannya persis lampiran',
        BsCogsSetupComponent::where('setup_id', $seluruh->first()?->id)->orderBy('urut')->pluck('nama')->all()
        === ['LAC Tanah', 'Infrastruktur', 'Undertable', 'License Permit']);

    echo "\n=== 9. Penjaga simpan & izin ===\n";

    $put = function (array $data) {
        $r = \Illuminate\Http\Request::create('/x', 'PUT', $data);
        $r->setUserResolver(fn () => auth()->user());

        return $r;
    };
    // Saleable + RTH > Luas Lahan berarti Jalan negatif — angka lahan yang mustahil.
    $ctl->update($put([
        'luas_lahan' => 1_000, 'saleable' => 800, 'rth' => 300, 'jumlah_unit' => null,
        'dasar_tanah' => 'saleable', 'termasuk_lebih_tanah' => false,
    ]), $setup);
    $ok('Saleable + RTH melebihi Luas Lahan ditolak',
        (float) $setup->fresh()->luas_lahan !== 1_000.0,
        'lahan yang tidak konsisten menghasilkan tarif per m2 yang terlihat wajar');

    // Izin: baca cogs.budget.view, tulis businesssupport.setup.manage. Diuji lewat PERILAKU,
    // bukan mencari teks — nama izin yang sama muncul beberapa kali dalam satu berkas.
    $pengguna = fn (array $izin) => new class($izin)
    {
        public int $id = 0;

        public string $name = 'Penguji';

        public function __construct(private array $izin) {}

        public function can($p, $arguments = []): bool { return in_array((string) $p, $this->izin, true); }
    };
    $status = null;
    try {
        $r = \Illuminate\Http\Request::create('/x', 'PUT', ['komponen' => []]);
        $r->setUserResolver(fn () => $pengguna(['cogs.budget.view']));
        $ctl->simpanKomponen($r, $setup);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $status = $e->getStatusCode(); }
    $ok('izin BACA saja ditolak menyimpan pos biaya (403)', $status === 403,
        'hasil: '.var_export($status, true));

    $statusBaca = null;
    try {
        $r = \Illuminate\Http\Request::create('/x', 'GET');
        $r->setUserResolver(fn () => $pengguna(['businesssupport.setup.manage']));
        app(K::class)->show($r, $setup);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $statusBaca = $e->getStatusCode(); }
    $ok('  izin TULIS saja tidak membuka layarnya (403)', $statusBaca === 403);

    $ok('tidak membuat izin baru (tidak ada izin cogs.setupcogs.*)',
        \Spatie\Permission\Models\Permission::where('name', 'like', 'cogs.setupcogs%')->count() === 0,
        'izin baru di pemasangan ini lahir yatim dan menunya hilang tanpa galat');

    echo "\n=== 10. Aritmetika langsung (tanpa layar) ===\n";

    $kal = app(CogsSetupKalkulator::class);
    $hitung = $kal->hitung(
        ['luas_lahan' => 31_933, 'saleable' => 17_150, 'rth' => 9_579.9, 'jumlah_unit' => 199,
            'dasar_tanah' => 'saleable', 'termasuk_lebih_tanah' => false],
        [['nama' => 'X', 'dasar' => 'tanah', 'nilai' => 33_517_750_362.22]],
        [],
    );
    $ok('tanpa unit pun TOTAL & COGS Saleable tetap terhitung',
        $sama($hitung['total']['total_cogs'], 33_517_750_362.22)
        && abs($hitung['total']['cogs_saleable'] - 1_954_387.7762228134) < 0.005);
    $ok('  tetapi posnya tidak dibagi ke siapa pun, dengan alasan',
        $hitung['komponen'][0]['terbagi'] === 0.0 && is_string($hitung['komponen'][0]['alasan']));

    // Luas nol tidak pernah dibagi.
    $nol = $kal->hitung(
        ['luas_lahan' => 0, 'saleable' => 0, 'rth' => 0, 'jumlah_unit' => null,
            'dasar_tanah' => 'unit', 'termasuk_lebih_tanah' => false],
        [['nama' => 'Y', 'dasar' => 'tanah', 'nilai' => 1_000]],
        [['kode' => 'U1', 'tipe' => null, 'blok' => null, 'no_unit' => null,
            'luas_tanah' => 0, 'luas_bangunan' => 0, 'luas_lebih_tanah' => 0]],
    );
    $ok('luas nol tidak pernah menjadi pembagi', $nol['komponen'][0]['tarif'] === null
        && $nol['total']['cogs_gross'] === null && $nol['total']['cogs_saleable'] === null);

    // Kelebihan tanah: setelan, dan kedua jumlahnya dilaporkan supaya yang benar terlihat.
    $lebih = $kal->hitung(
        ['luas_lahan' => 200, 'saleable' => 120, 'rth' => 0, 'jumlah_unit' => null,
            'dasar_tanah' => 'unit', 'termasuk_lebih_tanah' => true],
        [['nama' => 'Z', 'dasar' => 'tanah', 'nilai' => 1_200]],
        [['kode' => 'U1', 'tipe' => null, 'blok' => null, 'no_unit' => null,
            'luas_tanah' => 100, 'luas_bangunan' => 0, 'luas_lebih_tanah' => 20]],
    );
    $ok('kelebihan tanah ikut saat setelannya menyala', $sama($lebih['unitTotal']['lt'], 120));
    $ok('  dan kedua jumlahnya tetap dilaporkan terpisah',
        $sama($lebih['unitTotal']['lt_polos'], 100) && $sama($lebih['unitTotal']['lt_lebih'], 20),
        'tanpa keduanya, operator tidak bisa tahu setelan mana yang cocok dengan Saleable');

    echo "\n=== 11. Perkabelan layar & menu ===\n";

    foreach (['bs.cogs.setupcogs.index', 'bs.cogs.setupcogs.show', 'bs.cogs.setupcogs.store',
        'bs.cogs.setupcogs.update', 'bs.cogs.setupcogs.komponen', 'bs.cogs.setupcogs.destroy'] as $nama) {
        $ok('rute '.$nama.' terdaftar', app('router')->getRoutes()->getByName($nama) !== null);
    }

    $nav = file_get_contents(base_path('resources/js/Components/Layout/navGroups.ts'));
    $ok('menu berdiri DI BAWAH grup Komponen COGS',
        (bool) preg_match("/'Komponen COGS'.*?'Set Up COGS'.*?'Saldo Awal Realisasi'/s", $nav),
        'user meminta letaknya tepat di bawah Komponen COGS');
    // 'cogs.setup.*' akan ikut mencocoki izin mati cogs.setup.provision.manage — pemegangnya
    // akan melihat menu ini tanpa pernah diberi izinnya.
    // Diperiksa pada DAFTAR IZIN butirnya, bukan dengan mencari kata di seluruh berkas: kata
    // "cogs.setup.*" juga muncul di komentar penjelas tepat di atas butir menu ini.
    preg_match('/\{ label: \'Set Up COGS\',[^\n]*permission: \[([^\]]*)\]/', $nav, $m);
    $ok('  izin menunya BUKAN wildcard cogs.setup.*',
        isset($m[1]) && ! str_contains($m[1], 'cogs.setup.'),
        'izin butirnya: '.($m[1] ?? 'butir menu tidak ketemu')
        ." — 'cogs.setup.*' ikut mencocoki izin mati cogs.setup.provision.manage");

    $hub = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Cogs/Hub.vue'));
    $sec = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Cogs/Section.vue'));
    $ok('terdaftar juga di Hub COGS', str_contains($hub, 'bs.cogs.setupcogs.index'),
        'hub adalah pintu masuk kedua; sub-modul yang hanya ada di sidebar tak terlihat dari sana');
    $ok('terdaftar juga di halaman Section', str_contains($sec, "'setup-cogs'")
        && str_contains($sec, 'bs.cogs.setupcogs.index'));
    $ok('  judul Section-nya terdaftar', str_contains($sec, "'setup-cogs': 'Set Up COGS'"),
        'slug tanpa judul merender halaman kosong berjudul "Sub-Modul", tanpa 404');
    // summary di Section.vue dirender sebagai :href — kalimat di sana menjadi tautan rusak.
    $ok('  butirnya tidak memakai summary', (bool) preg_match(
        "/'setup-cogs': \[\s*\{ label: '[^']+', route: r\('bs\.cogs\.setupcogs\.index'\) \},/", $sec),
        'Section.vue merender summary sebagai alamat, bukan keterangan');

    $vue = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Cogs/SetupCogs/Show.vue'));
    $ok('layar memuat blok Perhitungan Lahan, Pos Biaya, Rekonsiliasi, Budget per Unit',
        str_contains($vue, 'Perhitungan Lahan') && str_contains($vue, 'Pos Biaya')
        && str_contains($vue, 'Rekonsiliasi') && str_contains($vue, 'Budget per Unit'));
    $ok('  kolom LT dan LB ada di tabel unit', str_contains($vue, '>LT (m²)<') && str_contains($vue, '>LB (m²)<'));
    $ok('  Jalan tidak bisa diketik', ! (bool) preg_match('/v-model="lahan\.jalan"/', $vue),
        'Jalan adalah rumus; membuatnya bisa diketik membuat ketiganya menyimpang dari Luas Lahan');

    $tuntas = true;
} catch (\Throwable $e) {
    $gagal++;
    echo "\n  GAGAL (galat tak tertangani) — ".get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo ($gagal === 0 && $tuntas) ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
    echo "(data uji di ipro_bo maupun $FO dibatalkan)\n";
}
