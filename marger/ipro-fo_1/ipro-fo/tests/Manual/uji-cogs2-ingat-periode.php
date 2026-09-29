<?php

/**
 * COGS Baru — berpindah sub-modul lewat sidebar TETAP di periode yang sama.
 *
 * Sejak keempat sub-modul berdiri sebagai layar yang terpisah, sidebar adalah jalan utama
 * berpindah di antara keempatnya. Butir menunya tidak membawa nomor periode, jadi rute
 * 'bs.cogs2.buka' yang memutuskan mendarat di mana.
 *
 * Yang dijaga, berurut dari yang paling mahal:
 *
 *  1. PERIODE YANG TERAKHIR DIBUKA-lah yang dipakai, bukan yang terakhir DISUNTING. "Terakhir
 *     disunting" berpindah begitu ada periode lain yang tersimpan — termasuk oleh orang lain —
 *     sehingga operator yang sedang mengerjakan JRK3 bisa mengetik Actual Costing ke JRK1 tanpa
 *     satu pun galat: angkanya tetap masuk dan tetap terlihat wajar.
 *  2. INGATANNYA DIPERBARUI TIAP LAYAR DIBUKA, jadi berpindah periode lewat Daftar Periode
 *     langsung terbawa ke sub-modul berikutnya.
 *  3. PERIODE YANG SUDAH DIHAPUS tidak membuat menunya 404 — ia jatuh kembali ke yang terakhir
 *     disunting.
 *  4. TANPA SESI SEKALIPUN (mis. permintaan tanpa middleware sesi) pintunya tetap bekerja, bukan
 *     melempar galat.
 *  5. KOMPONEN tetap terbawa, dan komponen yang tidak dikenal jatuh ke 'tanah' — bukan 404.
 *
 * Seluruh tulisan dibungkus transaksi lalu DIBATALKAN.
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-cogs2-ingat-periode.php';"
 */

use App\Domain\BusinessSupport\Models\BsCogs2Periode;
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

$ctl = app(K::class);

/** Satu sesi yang HIDUP sepanjang uji — persis seperti satu operator dengan satu peramban. */
$sesi = app('session.store');
$sesi->start();

/** Permintaan ber-sesi, seperti yang datang lewat middleware web. */
$req = function (bool $bersesi = true) use ($sesi) {
    $r = \Illuminate\Http\Request::create('/uji', 'GET');
    $r->setUserResolver(fn () => auth()->user());
    if ($bersesi) { $r->setLaravelSession($sesi); }

    return $r;
};

/** Ke periode mana pintu sidebar mendarat? Dipulangkan sebagai nomor periode dari URL-nya. */
$mendarat = function (string $layar, ?string $komponen = null, bool $bersesi = true) use ($ctl, $req) {
    $resp = $ctl->buka($req($bersesi), $layar, $komponen);

    return ['url' => $resp->getTargetUrl(), 'status' => $resp->getStatusCode()];
};
$nomorDi = function (string $url): int {
    preg_match('#/cogs2/(\d+)/#', $url, $m);

    return (int) ($m[1] ?? 0);
};

DB::beginTransaction();

try {
    // Dua periode. B sengaja dibuat BELAKANGAN sehingga ia yang "terakhir disunting".
    $a = BsCogs2Periode::create([
        'tenant_id' => $tenant, 'lokasi_id' => 970701, 'lokasi_kode' => 'ZIP-A',
        'lokasi_nama' => 'PERIODE INGAT A', 'tahap' => '001',
    ]);
    $b = BsCogs2Periode::create([
        'tenant_id' => $tenant, 'lokasi_id' => 970702, 'lokasi_kode' => 'ZIP-B',
        'lokasi_nama' => 'PERIODE INGAT B', 'tahap' => '002',
    ]);
    // Dipaksa agar B benar-benar yang terakhir disunting, apa pun resolusi jam basis datanya.
    DB::table('bs_cogs2_periode')->where('id', $a->id)->update(['updated_at' => now()->subDay()]);
    DB::table('bs_cogs2_periode')->where('id', $b->id)->update(['updated_at' => now()]);

    echo "\n── 1. Belum ada yang dibuka → jatuh ke yang terakhir disunting ──────────\n";
    $sesi->forget('cogs2.periode');
    $h = $mendarat('rincian');
    $ok('tanpa ingatan, pintu sidebar membuka periode yang terakhir disunting (B)',
        $nomorDi($h['url']) === $b->id, 'mendarat di '.$h['url'].', harusnya periode #'.$b->id);

    echo "\n── 2. Membuka periode A → sidebar ikut ke A, bukan ke B ─────────────────\n";
    // Operator membuka Perhitungan COGS periode A dari Daftar Periode.
    $ctl->perhitungan($req(), $a);
    $ok('  layar yang dibuka mencatat periodenya di sesi',
        (int) $sesi->get('cogs2.periode') === $a->id,
        'isi sesi: '.var_export($sesi->get('cogs2.periode'), true));

    foreach (['perhitungan' => null, 'kategori' => 'housing', 'actual' => 'infra', 'rincian' => null] as $layar => $komp) {
        $h = $mendarat($layar, $komp);
        $ok('  sidebar → '.$layar.' mendarat di periode A (bukan B, yang lebih baru disunting)',
            $nomorDi($h['url']) === $a->id, 'mendarat di '.$h['url']);
    }

    echo "\n── 3. Komponen ikut terbawa, yang tak dikenal jatuh ke tanah ────────────\n";
    foreach (['tanah', 'housing', 'infra', 'license'] as $k) {
        $h = $mendarat('kategori', $k);
        $ok('  Set Up Kategori COGS-'.$k.' membuka komponen yang diklik',
            str_ends_with($h['url'], '/kategori/'.$k), $h['url']);
    }
    foreach (['tanah', 'housing', 'infra', 'license'] as $k) {
        $h = $mendarat('actual', $k);
        $ok('  Actual Costing COGS-'.$k.' membuka komponen yang diklik',
            str_ends_with($h['url'], '/actual/'.$k), $h['url']);
    }
    $h = $mendarat('kategori', 'ngawur');
    $ok('  komponen yang tidak dikenal jatuh ke tanah, bukan 404',
        str_ends_with($h['url'], '/kategori/tanah'), $h['url']);

    echo "\n── 4. Berpindah periode lewat layar → ingatannya ikut berpindah ─────────\n";
    $ctl->actual($req(), $b, 'license');
    $ok('  membuka Actual Costing periode B memperbarui ingatannya',
        (int) $sesi->get('cogs2.periode') === $b->id);
    $h = $mendarat('rincian');
    $ok('  sidebar → Rincian COGS kini mendarat di B', $nomorDi($h['url']) === $b->id, $h['url']);

    echo "\n── 5. Periode yang diingat sudah dihapus ────────────────────────────────\n";
    // A diingat, lalu dihapus. Menu tidak boleh 404 — ia jatuh ke yang terakhir disunting.
    $ctl->perhitungan($req(), $a);
    DB::table('bs_cogs2_periode')->where('id', $a->id)->delete();
    $h = $mendarat('perhitungan');
    $ok('  pintunya jatuh ke periode yang terakhir disunting, bukan 404',
        $nomorDi($h['url']) === $b->id, 'mendarat di '.$h['url']);

    echo "\n── 6. Permintaan tanpa sesi tetap bekerja ───────────────────────────────\n";
    $h = $mendarat('kategori', 'tanah', false);
    $ok('  tanpa sesi, pintunya tetap mendarat di sebuah periode',
        $nomorDi($h['url']) === $b->id, 'mendarat di '.$h['url']);

    echo "\n── 7. Satu-satunya nama sub-modul ada di submodul.ts ────────────────────\n";
    // Judul di layar TIDAK boleh ditulis ulang di tiap layar: dua nama untuk satu layar membuat
    // orang mengira ia membuka layar yang lain.
    $sub = file_get_contents(base_path('resources/js/Components/Cogs2/submodul.ts'));
    $kepala = file_get_contents(base_path('resources/js/Components/Cogs2/Kepala.vue'));
    $nav = file_get_contents(base_path('resources/js/Components/Layout/navGroups.ts'));

    foreach (['Perhitungan COGS', 'Set Up Kategori COGS', 'Actual Costing COGS', 'Rincian COGS'] as $nama) {
        $ok('  "'.$nama.'" terdaftar di submodul.ts',
            (bool) preg_match('/nama:\s*\''.preg_quote($nama, '/').'\'/', $sub));
    }
    $ok('  Kepala.vue mengambil judulnya dari submodul.ts, tidak menulis sendiri',
        str_contains($kepala, "from './submodul'") && str_contains($kepala, 'judulLayar('));
    $ok('  Kepala.vue tidak lagi memasang deretan tab antar sub-modul',
        ! str_contains($kepala, 'Layar COGS Baru'),
        'nav aria-label="Layar COGS Baru" masih ada — keempat sub-modul kembali terlihat satu halaman ber-tab');
    $ok('  keempat butir menunya tetap berdiri sendiri di sidebar',
        substr_count($nav, "href: 'bs.cogs2.buka'") === 10,
        'ditemukan '.substr_count($nav, "href: 'bs.cogs2.buka'").' butir, harusnya 10 (1+4+4+1)');

    $tuntas = true;
} catch (\Throwable $e) {
    $gagal++;
    echo "\n  GAGAL (galat tak tertangani) — ".get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
    $sesi->forget('cogs2.periode');

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo ($gagal === 0 && $tuntas) ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
    echo "(data uji dibatalkan)\n";
}
