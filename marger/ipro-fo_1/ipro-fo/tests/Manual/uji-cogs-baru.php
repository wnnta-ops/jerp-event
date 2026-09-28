<?php

/**
 * COGS Baru — Perhitungan COGS · Set Up Kategori · Actual Costing · Rincian COGS.
 *
 * UJI UTAMANYA ADA DUA.
 *
 * ┌─ 1. ANGKA LAMPIRAN HARUS KELUAR PERSIS ──────────────────────────────────────────┐
 * │ Seluruh isi "MODUL HPP.xlsx" dimasukkan apa adanya — 22 baris SPK Housing, 6 tipe │
 * │ rumah, 199 unit — lalu dituntut keluar sampai sen:                                │
 * │                                                                                   │
 * │   Lahan 31.933 · Saleable 17.150 (53,706%) · RTH 9.579,9 (30%) · Jalan 5.203,1    │
 * │   LAC Tanah    16.180.000.000  ÷ Saleable =   943.440,23 /m²                      │
 * │   Housing      26.686.314.285  (per TIPE, bukan tarif tunggal)                    │
 * │   Infrastruktur 9.035.875.231  ÷ Saleable =   526.873,19 /m²                      │
 * │   License       2.955.275.500  ÷ Saleable =   172.319,27 /m²                      │
 * │   TOTAL COGS   54.857.465.016  ÷ Saleable Belum Booked = 3.198.686,01 /m²         │
 * │   Housing per meter: CICA 3.466.025,85 · CICA HOOK 3.551.356,18 · CAMAR HOOK      │
 * │     3.567.090,25 · CAMAR 3.864.347,77 · CALADI 4.037.402,08 · RUHA CICI 1.865.106,69│
 * └───────────────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ 2. RANTAI MELINGKAR HARUS AMAN DI TEMPAT EXCEL MENYERAH ────────────────────────┐
 * │ Di lampiran, realisasi tiap unit memakai angka yang dijumlahkan dari kolom        │
 * │ realisasi itu sendiri. Excel menyelesaikannya dengan iterasi, dan iterasi itu     │
 * │ DIVERGEN begitu luas unit "sudah booked + realisasi costing" melampaui luas unit  │
 * │ "belum booked" — pada rasio 0,9–1,0 ia bahkan berhenti di batas 100 putaran dan   │
 * │ memulangkan angka yang terlihat wajar tetapi meleset puluhan persen, tanpa satu   │
 * │ pun peringatan.                                                                  │
 * │                                                                                   │
 * │ Di sini rantainya dipecahkan ALJABAR:  r = (Aktual − Terkunci) ÷ Σ luas penanggung │
 * │ Sekali bagi, tanpa iterasi. Yang diuji: pada kasus yang membuat Excel divergen,   │
 * │ angkanya tetap benar dan Σ realisasi + Σ terkunci = biaya aktual, PERSIS.         │
 * └───────────────────────────────────────────────────────────────────────────────────┘
 *
 * Yang juga dijaga:
 *  • Pembagi nol tidak pernah dibagi; sisa biayanya dilaporkan sebagai tak terbebankan.
 *  • Tarif NEGATIF ditandai — bukan hipotetis: budget Tanah lampiran (16,18 M) sudah melampaui
 *    biaya aktualnya (12,15 M), jadi begitu ±75% luas terkunci, tarifnya berbalik negatif.
 *  • Tipe unit tanpa padanan di master Housing ditandai, bukan diam-diam bernilai nol.
 *  • "% HPP/Revenue" saat revenue nol memulangkan null, bukan #DIV/0! seperti di lampiran.
 *  • Unit belum booked tidak boleh mengunci pilihan costing.
 *  • Satu periode per cakupan; kode unit tidak boleh kembar.
 *  • Sidebar: COGS lama bernama "COGS Lama", COGS Baru berdiri sebagai modul sendiri dengan
 *    keempat grup yang diminta.
 *
 * Seluruh tulisan dibungkus transaksi lalu DIBATALKAN.
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-cogs-baru.php';"
 */

use App\Domain\BusinessSupport\Models\BsCogs2Actual;
use App\Domain\BusinessSupport\Models\BsCogs2HousingTipe;
use App\Domain\BusinessSupport\Models\BsCogs2Kategori;
use App\Domain\BusinessSupport\Models\BsCogs2Periode;
use App\Domain\BusinessSupport\Models\BsCogs2Unit;
use App\Domain\BusinessSupport\Services\Cogs2Kalkulator;
use App\Http\Controllers\Web\BusinessSupport\Cogs\Cogs2Controller as K;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$lulus = 0;
$gagal = 0;
$tuntas = false;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
/** Uang & luas: sama bila selisihnya di bawah setengah sen. */
$sama = fn ($a, $b, float $eps = 0.005) => $a !== null && abs((float) $a - (float) $b) < $eps;

$user = \App\Models\User::all()->first(fn ($u) => $u->hasRole('SuperAdmin')) ?? \App\Models\User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);
$tenant = (int) $user->tenant_id;

$kal = app(Cogs2Kalkulator::class);
$muat = fn (BsCogs2Periode $p) => $kal->hitung(
    $p->fresh(),
    BsCogs2HousingTipe::where('periode_id', $p->id)->orderBy('urut')->get(),
    BsCogs2Kategori::where('periode_id', $p->id)->orderBy('urut')->get(),
    BsCogs2Actual::where('periode_id', $p->id)->orderBy('urut')->get(),
    BsCogs2Unit::where('periode_id', $p->id)->orderBy('urut')->get(),
);

DB::beginTransaction();

try {
    foreach (['bs_cogs2_periode', 'bs_cogs2_housing_tipe', 'bs_cogs2_kategori', 'bs_cogs2_actual', 'bs_cogs2_unit'] as $t) {
        $ok('prasyarat: tabel '.$t.' ada', Schema::hasTable($t), 'jalankan migrasi 2026_09_27_100000_create_bs_cogs2.php');
    }

    // ── Periode, persis blok Perhitungan Lahan lampiran ───────────────────────────
    $p = BsCogs2Periode::create([
        'tenant_id' => $tenant, 'lokasi_id' => 999001, 'lokasi_kode' => 'JRK3',
        'lokasi_nama' => 'JAVA RESIDENCE KRIAN CLUSTER 3', 'tahap' => '001 & 002',
        'luas_lahan' => 31_933, 'saleable' => 17_150, 'rth' => 9_579.9,
        'luas_bangunan' => 7_411, 'jumlah_unit' => 199,
    ]);

    // ── Tipe rumah + jumlah unit + luas tanah yang dipakai fikstur ────────────────
    // Σ LB = 36×71 + 39×26 + 39×22 + 36×59 + 39×16 + 47×5 = 7.411  (persis lampiran)
    // Σ LT = 84×71 + 90×26 + 90×22 + 84×59 + 90×16 + 94×5 = 17.150 (persis Saleable)
    $TIPE = [
        // nama,        LB, jumlah unit, LT per unit
        ['CICA',        36, 71, 84],
        ['CICA HOOK',   39, 26, 90],
        ['CAMAR HOOK',  39, 22, 90],
        ['CAMAR',       36, 59, 84],
        ['CALADI',      39, 16, 90],
        ['RUHA CICI',   47,  5, 94],
    ];
    foreach ($TIPE as $i => [$nama, $lb, $n, $lt]) {
        BsCogs2HousingTipe::create(['tenant_id' => $tenant, 'periode_id' => $p->id,
            'nama' => $nama, 'luas_bangunan' => $lb, 'urut' => $i + 1]);
    }

    // ── Set Up Kategori — keempat komponen, angka lampiran ────────────────────────
    BsCogs2Kategori::create(['tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => 'tanah',
        'lokasi' => 'JRK3', 'tahap' => '001 & 002', 'luas_tanah' => 31_933,
        'harga_rata2' => 506_685.873548, 'nominal' => round(31_933 * 506_685.873548, 2), 'urut' => 1]);

    foreach ([['URUGAN', 3_769_246_000], ['INFRASTRUKTUR', 5_266_629_231]] as $i => [$kat, $nom]) {
        BsCogs2Kategori::create(['tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => 'infra',
            'lokasi' => 'JRK3', 'tahap' => '002', 'kategori' => $kat, 'nominal' => $nom, 'urut' => $i + 1]);
    }
    foreach ([['SERTIFIKASI', 1_891_470_776], ['PERIZINAN', 1_063_804_724]] as $i => [$kat, $nom]) {
        BsCogs2Kategori::create(['tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => 'license',
            'lokasi' => 'JRK3', 'tahap' => '002', 'kategori' => $kat, 'nominal' => $nom, 'urut' => $i + 1]);
    }

    // 22 baris SPK Housing — seluruhnya, karena totalnya (26.686.314.285) dan budget per tipe
    // hanya cocok bila tidak ada satu pun yang terlewat.
    $HOUSING = [
        ['RUHA CICI',  'SPK 072 Housing Cluster 3',                            366_282_474.00],
        ['CICA',       'SPK 028 JRK Housing Cluster 3 Tahap 1',              7_106_561_179.40],
        ['CICA HOOK',  'SPK 028 JRK Housing Cluster 3 Tahap 1',              2_835_414_952.69],
        ['CICA',       'SPK 050 MUP-MUK Kanopi JRKC3 Tahap 1',                 729_951_000.00],
        ['CICA HOOK',  'SPK 050 MUP-MUK Kanopi JRKC3 Tahap 1',                 267_306_000.00],
        ['CICA HOOK',  'SPK 030 Rumah Contoh Cluster 3',                       123_862_700.96],
        ['CALADI',     'SPK 030 Rumah Contoh Cluster 3',                       127_190_584.67],
        ['CAMAR',      'SPK 037 JRK Housing CLuster 3 Tahap 2',              7_358_067_000.00],
        ['CAMAR HOOK', 'SPK 037 JRK Housing CLuster 3 Tahap 2',              2_743_686_000.00],
        ['CALADI',     'SPK 037 JRK Housing CLuster 3 Tahap 2',              2_161_692_000.00],
        ['CICA',       'SPK 061 - Draft SPK Pekerjaan kanopi Cluster 3 Tahap 1', 445_981_513.20],
        ['CICA HOOK',  'SPK 061 - Draft SPK Pekerjaan kanopi Cluster 3 Tahap 1', 163_317_173.85],
        ['CAMAR HOOK', 'SPK 061 - Draft SPK Pekerjaan kanopi Cluster 3 Tahap 1', 138_191_454.79],
        ['CAMAR',      'SPK 061 - Draft SPK Pekerjaan kanopi Cluster 3 Tahap 1', 370_604_356.04],
        ['CALADI',     'SPK 061 - Draft SPK Pekerjaan kanopi Cluster 3 Tahap 1', 100_502_876.21],
        ['RUHA CICI',  'SPK 061 - Draft SPK Pekerjaan kanopi Cluster 3 Tahap 1',  31_407_148.82],
        ['CICA',       'SPK Lain-lain',                                         576_668_375.87],
        ['CICA HOOK',  'SPK Lain-lain',                                         211_174_334.82],
        ['CAMAR HOOK', 'SPK Lain-lain',                                         178_685_975.62],
        ['CAMAR',      'SPK Lain-lain',                                         479_203_298.25],
        ['CALADI',     'SPK Lain-lain',                                         129_953_436.81],
        ['RUHA CICI',  'SPK Lain-lain',                                          40_610_449.00],
    ];
    foreach ($HOUSING as $i => [$kat, $spk, $nom]) {
        BsCogs2Kategori::create(['tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => 'housing',
            'lokasi' => 'JRK3', 'tahap' => '001 & 002', 'kategori' => $kat, 'no_spk' => $spk,
            'nominal' => $nom, 'urut' => $i + 1]);
    }

    // ── Actual Costing — satu baris ringkas per komponen, sejumlah total lampiran ──
    foreach ([
        ['tanah', 12_148_288_288.29],
        ['housing', 25_070_018_414.62],
        ['infra', 11_319_837_370.68],
        ['license', 3_750_678_203.00],
    ] as $i => [$komp, $nilai]) {
        BsCogs2Actual::create(['tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => $komp,
            'lokasi' => 'JRK3', 'tahap' => '001 & 002', 'keterangan' => 'Total realisasi '.$komp,
            'penambahan' => $nilai, 'pengurangan' => 0, 'urut' => $i + 1]);
    }

    // ── 199 unit ──────────────────────────────────────────────────────────────────
    $urut = 0;
    foreach ($TIPE as [$nama, $lb, $n, $lt]) {
        for ($i = 1; $i <= $n; $i++) {
            $urut++;
            BsCogs2Unit::create(['tenant_id' => $tenant, 'periode_id' => $p->id,
                'unit_rumah' => 'ZC2-'.str_pad((string) $urut, 3, '0', STR_PAD_LEFT),
                'nama_customer' => 'PENGUJI '.$urut, 'lokasi' => 'JRK3', 'tahap' => '001 & 002',
                'tipe' => $nama, 'lt' => $lt, 'lb' => $lb,
                'status' => 'belum', 'costing' => 'belum', 'urut' => $urut]);
        }
    }

    echo "\n=== 1. Perhitungan Lahan ===\n";

    $h = $muat($p);
    $L = $h['lahan'];
    $ok('Luas Lahan 31.933', $sama($L['luas_lahan'], 31_933));
    $ok('Saleable 17.150 = 53,706%', $sama($L['saleable'], 17_150)
        && abs($L['persen_saleable'] - 0.53706197350703) < 0.0000001);
    $ok('RTH 9.579,9 = 30,000%', $sama($L['rth'], 9_579.9) && abs($L['persen_rth'] - 0.3) < 0.0000001);
    $ok('Jalan 5.203,1 DITURUNKAN', $sama($L['jalan'], 5_203.1), 'jalan='.$L['jalan']);
    $ok('  persen Jalan 16,294%', abs($L['persen_jalan'] - 0.16293802649297) < 0.0000001);
    $ok('Luas Bangunan 7.411 = Σ LB unit', $sama($L['luas_bangunan'], 7_411) && $sama($h['unitTotal']['lb'], 7_411));
    $ok('Σ LT unit = Saleable 17.150', $sama($h['unitTotal']['lt'], 17_150), 'ΣLT='.$h['unitTotal']['lt']);
    $ok('199 unit terbaca', $h['unitTotal']['jumlah'] === 199);
    $ok('Jalan TIDAK disimpan sebagai kolom', ! Schema::hasColumn('bs_cogs2_periode', 'jalan'));

    echo "\n=== 2. Budget per komponen & tarif per m² (angka lampiran) ===\n";

    $P = $h['perhitungan'];
    $ok('LAC Tanah 16.180.000.000', $sama($P['tanah']['budget'], 16_180_000_000, 0.02), 'tanah='.$P['tanah']['budget']);
    $ok('  ÷ Saleable = 943.440,23 /m²', $sama($P['tanah']['budget_per_m2'], 943_440.23323615163, 0.01));
    $ok('Housing 26.686.314.285', $sama($P['housing']['budget'], 26_686_314_285, 0.5), 'housing='.$P['housing']['budget']);
    $ok('  TIDAK punya tarif per m² tunggal (per tipe)', $P['housing']['budget_per_m2'] === null,
        'luas bangunan tiap tipe berbeda; satu tarif akan salah untuk lima dari enam tipe');
    $ok('Infrastruktur 9.035.875.231', $sama($P['infra']['budget'], 9_035_875_231));
    $ok('  ÷ Saleable = 526.873,19 /m²', $sama($P['infra']['budget_per_m2'], 526_873.19131195336, 0.01));
    $ok('License Permit 2.955.275.500', $sama($P['license']['budget'], 2_955_275_500));
    $ok('  ÷ Saleable = 172.319,27 /m²', $sama($P['license']['budget_per_m2'], 172_319.27113702623, 0.01));
    $ok('TOTAL COGS 54.857.465.016', $sama($h['total']['budget'], 54_857_465_016, 0.5), 'total='.$h['total']['budget']);
    $ok('COGS Saleable Belum Booked 3.198.686,01 /m²', $sama($h['total']['per_belum'], 3_198_686.0067638485, 0.01),
        'per_belum='.$h['total']['per_belum']);
    // Di lampiran "COGS GROSS" dan "COGS Saleable" sama-sama dibagi Saleable Belum Booked sehingga
    // memulangkan angka identik di bawah dua nama berbeda. Di sini ketiganya dipisah dan dinamai.
    $ok('  COGS GROSS (÷ Luas Lahan) BERBEDA dan disebut sendiri',
        $sama($h['total']['per_lahan'], 54_857_465_016 / 31_933, 0.02)
        && abs($h['total']['per_lahan'] - $h['total']['per_belum']) > 1_000,
        'satu angka per m² tanpa keterangan penyebutnya adalah salah hitung yang paling mudah terjadi');

    echo "\n=== 3. Housing per tipe ===\n";

    $tipeMap = collect($h['tipe'])->keyBy('nama');
    $harapTipe = [
        ['CICA', 71, 124_776_930.54173367, 3_466_025.8483814909],
        ['CICA HOOK', 26, 138_502_890.85846442, 3_551_356.1758580622],
        ['CAMAR HOOK', 22, 139_116_519.56423366, 3_567_090.2452367605],
        ['CAMAR', 59, 139_116_519.56423366, 3_864_347.7656731573],
        ['CALADI', 16, 157_458_681.10579616, 4_037_402.0796357989],
        ['RUHA CICI', 5, 87_660_014.364233673, 1_865_106.6886007164],
    ];
    foreach ($harapTipe as [$nama, $n, $perUnit, $perMeter]) {
        $t = $tipeMap[$nama] ?? null;
        $ok('  '.$nama.': '.$n.' unit · per meter '.number_format($perMeter, 2, ',', '.'),
            $t !== null && $t['jumlah_unit'] === $n
            && $sama($t['budget_per_unit'], $perUnit, 0.02)
            && $sama($t['budget_per_meter'], $perMeter, 0.001),
            $t === null ? 'tipe tidak ketemu' : ('unit='.$t['jumlah_unit'].' perMeter='.$t['budget_per_meter']));
    }
    $ok('Σ budget housing per unit = total housing',
        $sama(array_sum(array_map(fn ($t) => $t['budget_per_unit'] * $t['jumlah_unit'], $h['tipe'])), 26_686_314_285, 1.0));

    // Budget Housing tiap UNIT harus dihitung dari LUAS BANGUNAN-nya, bukan luas tanah. Keduanya
    // sama-sama memulangkan angka yang besarnya masuk akal, jadi kekeliruan ini hanya terlihat
    // bila dicocokkan ke angkanya — unit CICA berluas bangunan 36 dan berluas tanah 84.
    $cica = collect($h['unit'])->firstWhere('tipe', 'CICA');
    $ok('  budget Housing per unit dihitung dari LUAS BANGUNAN, bukan luas tanah',
        $cica !== null && $sama($cica['budget']['housing'], 36 * 3_466_025.8483814909, 0.05),
        'CICA: housing='.($cica['budget']['housing'] ?? 'tidak ada')
        .' (LB 36 → '.round(36 * 3_466_025.8483814909, 2).', LT 84 → '.round(84 * 3_466_025.8483814909, 2).')');
    $ok('  Σ budget Housing seluruh unit = total budget Housing',
        $sama(array_sum(array_map(fn ($u) => (float) $u['budget']['housing'], $h['unit'])), 26_686_314_285, 1.0),
        'Σ='.array_sum(array_map(fn ($u) => (float) $u['budget']['housing'], $h['unit'])));
    // Dan tiga komponen lainnya memang dari luas TANAH.
    $ok('  budget Tanah/Infra/License per unit dihitung dari luas tanah',
        $cica !== null && $sama($cica['budget']['tanah'], 84 * 943_440.23323615163, 0.05)
        && $sama($cica['budget']['infra'], 84 * 526_873.19131195336, 0.05)
        && $sama($cica['budget']['license'], 84 * 172_319.27113702623, 0.05));

    echo "\n=== 4. Rantai melingkar — seluruh unit belum booked ===\n";

    $R = $h['rekon'];
    $harapTarif = [
        ['tanah', 12_148_288_288.29, 17_150],
        ['housing', 25_070_018_414.62, 7_411],
        ['infra', 11_319_837_370.68, 17_150],
        ['license', 3_750_678_203.00, 17_150],
    ];
    foreach ($harapTarif as [$k, $aktual, $bobot]) {
        $ok('  '.$k.': tarif = '.number_format($aktual / $bobot, 2, ',', '.').' /m²',
            $sama($R[$k]['tarif'], $aktual / $bobot, 0.0001) && $sama($R[$k]['bobot'], $bobot),
            'tarif='.$R[$k]['tarif'].' bobot='.$R[$k]['bobot']);
        $ok('    seluruh biaya aktual habis dibebankan (selisih nol)', $sama($R[$k]['selisih'], 0),
            'selisih='.$R[$k]['selisih']);
    }
    $ok('Σ realisasi seluruh unit = Σ biaya aktual', $sama($h['unitTotal']['realisasi'], $h['total']['aktual'], 0.02),
        'Σrealisasi='.$h['unitTotal']['realisasi'].' aktual='.$h['total']['aktual']);

    echo "\n=== 5. Titik Excel MENYERAH: booked-realisasi melampaui belum-booked ===\n";

    // Excel menyelesaikan rantai ini dengan iterasi, dan iterasi itu DIVERGEN begitu
    // Σ luas "sudah booked + realisasi costing" ≥ Σ luas "belum booked". Di sini 150 unit
    // dibuat booked+realisasi dan hanya 44 yang belum booked — rasio di mana Excel memulangkan
    // ±Infinity. Rumus tertutup tidak punya kelas kegagalan itu sama sekali.
    $semua = BsCogs2Unit::where('periode_id', $p->id)->orderBy('urut')->get();
    foreach ($semua->take(5) as $u) { $u->update(['status' => 'sudah', 'costing' => 'standard']); }
    foreach ($semua->slice(5, 150) as $u) { $u->update(['status' => 'sudah', 'costing' => 'realisasi']); }

    $h2 = $muat($p);
    $ltTerkunci = round((float) $semua->take(5)->sum(fn ($u) => (float) $u->lt), 2);
    $ltMenanggung = round(17_150 - $ltTerkunci, 2);
    $ok('lima unit terkunci, 150 booked-realisasi, 44 belum booked',
        $h2['unitTotal']['terkunci'] === 5 && $h2['unitTotal']['booked'] === 155);
    $ok('  Σ luas penanggung = seluruh unit TIDAK terkunci (bukan hanya yang belum booked)',
        $sama($h2['rekon']['tanah']['bobot'], $ltMenanggung),
        'bobot='.$h2['rekon']['tanah']['bobot'].' harap='.$ltMenanggung
        .' — memakai Σ luas belum-booked saja adalah yang membuat Excel divergen');

    foreach (['tanah', 'housing', 'infra', 'license'] as $k) {
        $tarif = ($h2['rekon'][$k]['aktual'] - $h2['rekon'][$k]['terkunci']) / $h2['rekon'][$k]['bobot'];
        $ok('  '.$k.': tarif tertutup = (Aktual − Terkunci) ÷ Σ luas penanggung',
            $sama($h2['rekon'][$k]['tarif'], $tarif, 0.0001), 'tarif='.$h2['rekon'][$k]['tarif']);
        $ok('    Σ realisasi + terkunci = biaya aktual, PERSIS',
            $sama($h2['rekon'][$k]['dibebankan'] + $h2['rekon'][$k]['terkunci'], $h2['rekon'][$k]['aktual']),
            'selisih='.$h2['rekon'][$k]['selisih']);
    }
    // Unit yang terkunci tidak menerima realisasi sepeser pun.
    $terkunciUnit = collect($h2['unit'])->where('terkunci', true);
    $ok('  unit terkunci menerima realisasi NOL', $terkunciUnit->count() === 5
        && $terkunciUnit->every(fn ($u) => abs((float) $u['realisasi_total']) < 0.005));
    // LICENSE diperlakukan SAMA dengan tiga komponen lain. Di lampiran kolom License memakai
    // syarat yang lebih longgar (menolkan SEMUA unit booked), sehingga unit booked+realisasi
    // kehilangan seluruh komponen license-nya dan unit belum-booked menanggung beban yang
    // bukan miliknya — totalnya tetap cocok sehingga kesalahan itu tak pernah terlihat.
    $bookedRea = collect($h2['unit'])->filter(fn ($u) => $u['status'] === 'sudah' && $u['costing'] === 'realisasi');
    $ok('  unit booked-realisasi TETAP menerima License Permit',
        $bookedRea->count() === 150 && $bookedRea->every(fn ($u) => (float) $u['realisasi']['license'] > 0),
        'di lampiran komponen License unit seperti ini nol, dan bebannya digeser ke unit belum-booked');

    echo "\n=== 6. Pembagi nol & tarif negatif ===\n";

    // Seluruh unit terkunci → tidak ada yang menanggung. Tidak boleh dibagi, dan sisanya
    // harus DILAPORKAN, bukan hilang.
    BsCogs2Unit::where('periode_id', $p->id)->update(['status' => 'sudah', 'costing' => 'standard']);
    $h3 = $muat($p);
    $ok('seluruh unit terkunci → tarif tidak dihitung', $h3['rekon']['tanah']['tarif'] === null
        && is_string($h3['rekon']['tanah']['alasan']),
        'alasan: '.var_export($h3['rekon']['tanah']['alasan'], true));
    $ok('  tidak ada INF/NAN yang lolos ke layar',
        is_float($h3['rekon']['tanah']['dibebankan']) && is_finite($h3['rekon']['tanah']['dibebankan']));
    $ok('  sisa biaya yang tak terbebankan dilaporkan',
        collect($h3['peringatan'])->contains(fn ($w) => $w['jenis'] === 'tak_terbebankan'),
        'peringatan: '.json_encode(array_column($h3['peringatan'], 'jenis')));

    // Tarif negatif: budget Tanah (16,18 M) lebih besar daripada aktualnya (12,15 M), jadi
    // mengunci cukup banyak unit membuat sisa yang harus dibebankan menjadi negatif.
    BsCogs2Unit::where('periode_id', $p->id)->update(['status' => 'belum', 'costing' => 'belum']);
    foreach (BsCogs2Unit::where('periode_id', $p->id)->orderBy('urut')->take(180)->get() as $u) {
        $u->update(['status' => 'sudah', 'costing' => 'standard']);
    }
    $h4 = $muat($p);
    $ok('budget terkunci melampaui aktual → tarif NEGATIF, dan ditandai',
        $h4['rekon']['tanah']['tarif'] !== null && $h4['rekon']['tanah']['tarif'] < 0
        && collect($h4['peringatan'])->contains(fn ($w) => $w['jenis'] === 'tarif_negatif'),
        'tarif='.$h4['rekon']['tanah']['tarif']);
    $ok('  rekonsiliasinya TETAP tertutup walau tarifnya negatif',
        $sama($h4['rekon']['tanah']['selisih'], 0), 'selisih='.$h4['rekon']['tanah']['selisih']);

    BsCogs2Unit::where('periode_id', $p->id)->update(['status' => 'belum', 'costing' => 'belum']);

    echo "\n=== 7. Keadaan yang menyesatkan harus ditandai ===\n";

    $u1 = BsCogs2Unit::where('periode_id', $p->id)->orderBy('urut')->first();
    $u1->update(['tipe' => 'TIPE TIDAK TERDAFTAR']);
    $h5 = $muat($p);
    $ok('tipe unit tanpa padanan di master Housing ditandai',
        collect($h5['peringatan'])->contains(fn ($w) => $w['jenis'] === 'tipe_yatim'),
        'budget Housing-nya nol tanpa jejak kalau tidak ditandai');
    $ok('  dan budget Housing unit itu memang nol', $sama($h5['unit'][0]['budget']['housing'], 0));
    $u1->update(['tipe' => 'CICA']);

    $u1->update(['status' => 'sudah', 'costing' => 'belum']);
    $h6 = $muat($p);
    $ok('unit booked tanpa pilihan costing ditandai',
        collect($h6['peringatan'])->contains(fn ($w) => $w['jenis'] === 'costing_belum'));
    $ok('  tetapi TETAP ikut ke penyebut sehingga rekonsiliasi tertutup',
        $sama($h6['rekon']['tanah']['selisih'], 0),
        'di lampiran unit seperti ini dibebani tetapi tidak ikut dijumlahkan, '
        .'sehingga totalnya melebihi biaya aktual tanpa ada yang memberi tahu');
    $u1->update(['status' => 'belum', 'costing' => 'belum']);

    $u1->update(['lb' => 0]);
    $ok('unit tanpa luas bangunan ditandai',
        collect($muat($p)['peringatan'])->contains(fn ($w) => $w['jenis'] === 'luas_nol'));
    $u1->update(['lb' => 36]);

    echo "\n=== 8. % HPP/Revenue tidak pernah #DIV/0! ===\n";

    $h7 = $muat($p);
    $ok('revenue kosong → null, bukan galat pembagian', $h7['unit'][0]['persen_hpp'] === null,
        'di lampiran sel ini berisi #DIV/0! di seluruh 199 baris');
    $u1->update(['booked_revenue' => 500_000_000]);
    $h8 = $muat($p);
    $ok('  revenue terisi → persentasenya dihitung',
        $h8['unit'][0]['persen_hpp'] !== null
        && $sama($h8['unit'][0]['persen_hpp'], $h8['unit'][0]['budget_total'] / 500_000_000, 0.000001));

    echo "\n=== 9. Penjaga simpan & izin ===\n";

    $ctl = app(K::class);
    $req = function (string $metode, array $data) {
        $r = \Illuminate\Http\Request::create('/x', $metode, $data);
        $r->setUserResolver(fn () => auth()->user());

        return $r;
    };

    // Unit belum booked tidak boleh mengunci pilihan costing.
    $ctl->simpanUnit($req('PUT', ['baris' => [[
        'unit_rumah' => 'ZC2-001', 'lt' => 84, 'lb' => 36, 'status' => 'belum', 'costing' => 'standard',
    ]]]), $p);
    $ok('unit belum booked tidak boleh mengunci costing',
        BsCogs2Unit::where('periode_id', $p->id)->count() === 199,
        'mengunci Standard Costing sebelum booked mengeluarkannya dari pembebanan realisasi');

    // Kode unit kembar ditolak.
    $ctl->simpanUnit($req('PUT', ['baris' => [
        ['unit_rumah' => 'SAMA', 'lt' => 1, 'lb' => 1, 'status' => 'belum', 'costing' => 'belum'],
        ['unit_rumah' => 'SAMA', 'lt' => 1, 'lb' => 1, 'status' => 'belum', 'costing' => 'belum'],
    ]]), $p);
    $ok('kode unit kembar ditolak', BsCogs2Unit::where('periode_id', $p->id)->count() === 199,
        'satu unit yang muncul dua kali menanggung realisasi dua kali');

    // Saleable + RTH > Luas Lahan ditolak.
    $ctl->simpanPerhitungan($req('PUT', [
        'luas_lahan' => 1_000, 'saleable' => 800, 'rth' => 300, 'luas_bangunan' => 0,
        'jumlah_unit' => null,
    ]), $p);
    $ok('Saleable + RTH melebihi Luas Lahan ditolak', (float) $p->fresh()->luas_lahan !== 1_000.0);

    // Nominal TANAH dihitung server, bukan diterima apa adanya.
    $ctl->simpanKategori($req('PUT', ['baris' => [[
        'lokasi' => 'JRK3', 'tahap' => '001 & 002', 'luas_tanah' => 100, 'harga_rata2' => 1_000_000,
        'nominal' => 999_999_999,
    ]]]), $p, 'tanah');
    $ok('nominal Tanah dihitung server (luas × harga), bukan diterima apa adanya',
        $sama((float) BsCogs2Kategori::where('periode_id', $p->id)->where('komponen', 'tanah')->value('nominal'), 100_000_000),
        'angka turunan yang diterima apa adanya bisa menyimpang dari rumusnya tanpa ada yang menolak');

    // Izin — diuji lewat PERILAKU, bukan mencari teks.
    $pengguna = fn (array $izin) => new class($izin)
    {
        public int $id = 0;

        public string $name = 'Penguji';

        public function __construct(private array $izin) {}

        public function can($per, $arguments = []): bool { return in_array((string) $per, $this->izin, true); }
    };
    $status = null;
    try {
        $r = \Illuminate\Http\Request::create('/x', 'PUT', ['baris' => []]);
        $r->setUserResolver(fn () => $pengguna(['cogs.budget.view']));
        $ctl->simpanUnit($r, $p);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $status = $e->getStatusCode(); }
    $ok('izin BACA saja ditolak menyimpan (403)', $status === 403, 'hasil: '.var_export($status, true));

    $statusBaca = null;
    try {
        $r = \Illuminate\Http\Request::create('/x', 'GET');
        $r->setUserResolver(fn () => $pengguna(['businesssupport.setup.manage']));
        $ctl->rincian($r, $p);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $statusBaca = $e->getStatusCode(); }
    $ok('  izin TULIS saja tidak membuka layarnya (403)', $statusBaca === 403);

    $ok('tidak membuat izin baru (tidak ada izin cogs2.*)',
        \Spatie\Permission\Models\Permission::where('name', 'like', 'cogs2%')->count() === 0
        && \Spatie\Permission\Models\Permission::where('name', 'like', 'cogs.baru%')->count() === 0,
        'izin baru di pemasangan ini lahir yatim dan menunya hilang tanpa galat');

    echo "\n=== 10. Satu periode per cakupan ===\n";

    $ctl->store($req('POST', ['lokasi_id' => 999001, 'tahap' => '001 & 002']));
    $ok('cakupan yang sudah ada ditolak',
        BsCogs2Periode::where('lokasi_id', 999001)->where('tahap', '001 & 002')->count() === 1,
        'dua periode untuk cakupan yang sama menghasilkan dua angka HPP per unit');
    $ctl->store($req('POST', ['lokasi_id' => 999001, 'tahap' => null]));
    $ok('tahap kosong disimpan "" (bukan NULL)',
        BsCogs2Periode::where('lokasi_id', 999001)->where('tahap', '')->count() === 1,
        'kunci unik MySQL tidak menahan NULL yang kembar');

    echo "\n=== 11. Rute, sidebar & layar ===\n";

    foreach (['bs.cogs2.index', 'bs.cogs2.store', 'bs.cogs2.destroy', 'bs.cogs2.buka',
        'bs.cogs2.perhitungan', 'bs.cogs2.perhitungan.simpan', 'bs.cogs2.kategori', 'bs.cogs2.kategori.simpan',
        'bs.cogs2.actual', 'bs.cogs2.actual.simpan', 'bs.cogs2.rincian', 'bs.cogs2.rincian.simpan',
        'bs.cogs2.tarik-unit'] as $nama) {
        $ok('rute '.$nama.' terdaftar', app('router')->getRoutes()->getByName($nama) !== null);
    }

    $nav = file_get_contents(base_path('resources/js/Components/Layout/navGroups.ts'));
    $ok('modul lama bernama "COGS Lama"', (bool) preg_match("/title: 'COGS Lama'/", $nav));
    $ok('  dan isinya TIDAK berubah (Setup … Saldo Awal Realisasi tetap di sana)',
        (bool) preg_match("/title: 'COGS Lama'.*?'Setup'.*?'Saldo Awal Realisasi'.*?title: 'COGS Baru'/s", $nav),
        'yang berpindah hanya namanya');
    $ok('modul baru bernama "COGS Baru"', (bool) preg_match("/title: 'COGS Baru'/", $nav));
    foreach (["'Perhitungan COGS'", "'Set Up Kategori COGS'", "'Actual Costing COGS'", "'Rincian COGS'"] as $grup) {
        $ok('  grup '.$grup.' ada di COGS Baru',
            (bool) preg_match("/title: 'COGS Baru'.*?\{ label: ".preg_quote($grup, '/')."/s", $nav));
    }
    foreach (['Tanah', 'Housing', 'Infra', 'License'] as $sub) {
        $ok('  butir Set Up Kategori COGS-'.$sub, str_contains($nav, "'Set Up Kategori COGS-".$sub."'"));
        $ok('  butir Actual Costing COGS-'.$sub, str_contains($nav, "'Actual Costing COGS-".$sub."'"));
    }
    // Nama modul di MODULE_ORDER harus sama PERSIS dengan title-nya; yang tidak cocok jatuh ke
    // belakang daftar tanpa satu pun galat. Diperiksa DI DALAM blok MODULE_ORDER saja — mencari
    // "'COGS Baru',"" di seluruh berkas juga mengenai baris `title: 'COGS Baru',`, sehingga
    // pemeriksaannya tetap lulus walau modulnya dicabut dari daftar urutan.
    preg_match('/export const MODULE_ORDER: string\[\] = \[(.*?)\];/s', $nav, $mo);
    $urutan = $mo[1] ?? '';
    $ok('kedua modul terdaftar di MODULE_ORDER',
        str_contains($urutan, "'COGS Lama',") && str_contains($urutan, "'COGS Baru',"),
        'blok MODULE_ORDER: '.trim(preg_replace('/\s+/', ' ', $urutan ?: 'tidak ketemu')));
    $ok('  dan nama "COGS" polos sudah tidak dipakai lagi', ! (bool) preg_match("/^\s*'COGS',\s*$/m", $nav));

    foreach (['Index', 'Perhitungan', 'Kategori', 'Actual', 'Rincian'] as $layar) {
        $ok('layar Cogs2/'.$layar.'.vue ada', file_exists(base_path('resources/js/Pages/BusinessSupport/Cogs2/'.$layar.'.vue')));
    }
    $rincian = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Cogs2/Rincian.vue'));
    $ok('  Rincian menampilkan rekonsiliasi & peringatan',
        str_contains($rincian, 'Rekonsiliasi Pembebanan') && str_contains($rincian, 'hitung.peringatan'));
    $ok('  dan punya grafik Budget vs Actual Costing', str_contains($rincian, 'Budget vs Actual Costing'));

    // Palet warna: satu tempat, dan kedua mode DIPILIH (bukan dibalik otomatis).
    $kepala = file_get_contents(base_path('resources/js/Components/Cogs2/Kepala.vue'));
    $ok('warna komponen didefinisikan untuk mode terang DAN gelap',
        str_contains($kepala, '--c2-tanah: #2a78d6') && str_contains($kepala, '--c2-tanah: #3987e5')
        && str_contains($kepala, "prefers-color-scheme: dark") && str_contains($kepala, "data-theme='dark'"),
        'langkah gelap adalah langkah tersendiri, bukan pembalikan otomatis');

    $tuntas = true;
} catch (\Throwable $e) {
    $gagal++;
    echo "\n  GAGAL (galat tak tertangani) — ".get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo ($gagal === 0 && $tuntas) ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
    echo "(data uji dibatalkan)\n";
}
