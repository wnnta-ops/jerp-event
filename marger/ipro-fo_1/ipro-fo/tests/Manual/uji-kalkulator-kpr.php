<?php

/**
 * Kalkulator KPR & Kategori Perhitungan KPR — sisi server.
 *
 * Mesin hitungnya diuji terpisah (tests/Manual/uji-kalkulator-kpr-mesin.cjs, sidik jari
 * fa564e9b). Berkas ini menjaga hal-hal yang TIDAK terlihat dari angka:
 *
 *  1. DUA IZIN BERBEDA UNTUK DUA LAYAR. Kalkulator dibuka siapa pun yang boleh melihat data
 *     KPR (bankloan.application.view); pengaturannya hanya bankloan.setup.*. Menyamakan
 *     keduanya membuat seluruh tim penjualan bisa mengubah suku bunga.
 *  2. NILAI GAMBAR TIDAK BOLEH DIPERCAYA. Dokumen config dirender ke atribut src. Nilai
 *     seperti javascript:… atau alamat luar harus DITOLAK jadi kosong — bukan disimpan dan
 *     diserahkan ke peramban.
 *  3. HASH KATA SANDI ADMIN APLIKASI LAMA tidak boleh ikut terkirim ke layar, tetapi HARUS
 *     tetap tersimpan — berkas "Unduh config.js" masih dipakai aplikasi aslinya.
 *  4. DOKUMENNYA DISUSUN ULANG DARI NILAI YANG LOLOS VALIDASI, bukan disimpan apa adanya.
 *     Skema yang tipenya diganti tidak boleh menyisakan field tipe lama — mesin hitung
 *     membaca berdasarkan type, dan sisa field lama membuat dokumennya membingungkan.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback); berkas gambar uji dihapus di akhir.
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-kalkulator-kpr.php';"
 */

use App\Domain\BankLoan\Models\KprCalculatorSetting;
use App\Http\Controllers\Web\BankLoan\KprCalculatorController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$req = function ($user, array $data = [], string $metode = 'GET', string $url = '/kpr/setup/perhitungan') {
    $r = \Illuminate\Http\Request::create($url, $metode, $data);
    $r->setUserResolver(fn () => $user);
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());

    return $r;
};
$props = function ($res, $r) {
    return json_decode((string) $res->toResponse($r)->getContent(), true);
};

$user = \App\Models\User::all()->first(fn ($u) => $u->can('bankloan.setup.manage') && $u->can('bankloan.application.view'));
if (! $user) { echo "Tidak ada user ber-izin cukup — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);

$berkasUji = 'kpr-calculator/ujigambar'.random_int(100000, 999999).'.png';
DB::beginTransaction();

try {
    $ctl = new KprCalculatorController;
    $tenantId = tenant_id();

    echo "\n=== 1. Pengaturan bawaan tersemai ===\n";

    $baris = KprCalculatorSetting::query()->first();
    $ok('barisnya ada', $baris !== null);
    $cfgDb = $baris?->config ?? [];
    $ok('config tersimpan sebagai array (cast json)', is_array($cfgDb));
    $ok('berisi 5 bank bawaan', count($cfgDb['banks'] ?? []) === 5, (string) count($cfgDb['banks'] ?? []));
    $ok('bulan berlaku terisi', ($cfgDb['effectiveMonth'] ?? '') !== '');
    $ok('hash kata sandi aplikasi lama TERSIMPAN', ($cfgDb['adminPasswordHash'] ?? '') !== '',
        'berkas hasil Unduh config.js tidak lagi bisa dipakai aplikasi aslinya');
    $ok('kolom gambar berisi PATH, bukan base64',
        str_starts_with((string) ($cfgDb['brandLogo'] ?? ''), '/img/kpr-calculator/'),
        (string) substr((string) ($cfgDb['brandLogo'] ?? ''), 0, 40));
    $ok('tidak ada satu pun data: URI tersisa di config',
        ! str_contains(json_encode($cfgDb), 'data:image'),
        'gambar base64 masih ikut disimpan — dokumennya jadi megabyte');

    echo "\n=== 2. Dua layar, dua izin ===\n";

    $isiKalk = $props($ctl->kalkulator($req($user, [], 'GET', '/kpr/kalkulator')), $req($user, [], 'GET', '/kpr/kalkulator'));
    $ok('halaman kalkulator = BankLoan/Kalkulator/Index', ($isiKalk['component'] ?? '') === 'BankLoan/Kalkulator/Index', $isiKalk['component'] ?? '');
    $ok('berkas .vue kalkulator ada', is_file(base_path('resources/js/Pages/'.($isiKalk['component'] ?? 'x').'.vue')));

    $isiSetup = $props($ctl->setup($req($user)), $req($user));
    $ok('halaman pengaturan = BankLoan/Setup/PerhitunganKpr/Index', ($isiSetup['component'] ?? '') === 'BankLoan/Setup/PerhitunganKpr/Index', $isiSetup['component'] ?? '');
    $ok('berkas .vue pengaturan ada', is_file(base_path('resources/js/Pages/'.($isiSetup['component'] ?? 'x').'.vue')));
    $ok('prop bisaUbah dikirim', array_key_exists('bisaUbah', $isiSetup['props'] ?? []));

    // Hash kata sandi TIDAK boleh sampai ke peramban.
    foreach ([['kalkulator', $isiKalk], ['pengaturan', $isiSetup]] as [$nama, $isi]) {
        $ok("hash kata sandi tidak dikirim ke layar $nama",
            ! array_key_exists('adminPasswordHash', $isi['props']['config'] ?? []),
            'hash ikut terkirim ke peramban');
    }

    echo "\n=== 3. Gerbang izin ===\n";

    $polos = new \App\Models\User([
        'tenant_id' => $tenantId, 'name' => 'UJI Tanpa Izin',
        'email' => 'uji-kalk-'.random_int(100000, 999999).'@contoh.test',
        'password' => bcrypt('rahasia'),
    ]);
    $polos->save();

    foreach ([
        'kalkulator' => fn () => $ctl->kalkulator($req($polos, [], 'GET', '/kpr/kalkulator')),
        'setup'      => fn () => $ctl->setup($req($polos)),
        'update'     => fn () => $ctl->update($req($polos, ['banks' => []], 'PUT')),
        'ekspor'     => fn () => $ctl->ekspor($req($polos)),
    ] as $aksi => $jalan) {
        $kode = 0;
        try { $jalan(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
        $ok("tanpa izin apa pun: $aksi ditolak", $kode === 403, (string) $kode);
    }

    // Pemegang izin BACA data KPR boleh memakai kalkulator, tetapi TIDAK boleh mengubah
    // pengaturannya — inilah pemisahan yang membuat modul ini aman dipakai tim penjualan.
    $penjual = new \App\Models\User([
        'tenant_id' => $tenantId, 'name' => 'UJI Penjualan',
        'email' => 'uji-jual-'.random_int(100000, 999999).'@contoh.test',
        'password' => bcrypt('rahasia'),
    ]);
    $penjual->save();
    $penjual->givePermissionTo('bankloan.application.view');
    $penjual = $penjual->fresh();
    $ok('user penjualan punya application.view tanpa setup.*',
        $penjual->can('bankloan.application.view') && ! $penjual->can('bankloan.setup.view') && ! $penjual->can('bankloan.setup.manage'));

    $boleh = true;
    try { $ctl->kalkulator($req($penjual, [], 'GET', '/kpr/kalkulator')); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $boleh = false; }
    $ok('penjualan BOLEH membuka kalkulator', $boleh);

    foreach (['setup' => fn () => $ctl->setup($req($penjual)), 'update' => fn () => $ctl->update($req($penjual, ['banks' => []], 'PUT'))] as $aksi => $jalan) {
        $kode = 0;
        try { $jalan(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
        $ok("penjualan DITOLAK pada $aksi", $kode === 403, (string) $kode);
    }

    /* MELIHAT PENGATURAN ≠ MENGUBAH SUKU BUNGA. Tanpa user yang punya setup.view TAPI TIDAK
       punya setup.manage, menurunkan gerbang update/unggah dari manage ke view tidak akan
       ketahuan sama sekali: user tanpa izin tetap ditolak, user penuh tetap diterima. */
    $pengawas = new \App\Models\User([
        'tenant_id' => $tenantId, 'name' => 'UJI Hanya Lihat Pengaturan',
        'email' => 'uji-lihat-set-'.random_int(100000, 999999).'@contoh.test',
        'password' => bcrypt('rahasia'),
    ]);
    $pengawas->save();
    $pengawas->givePermissionTo('bankloan.setup.view');
    $pengawas = $pengawas->fresh();
    $ok('user hanya-lihat-pengaturan terbentuk',
        $pengawas->can('bankloan.setup.view') && ! $pengawas->can('bankloan.setup.manage'));

    $bolehLihat = true;
    try { $ctl->setup($req($pengawas)); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $bolehLihat = false; }
    $ok('hanya-lihat BOLEH membuka pengaturan', $bolehLihat, 'gerbang setup terlalu ketat');
    $isiLihat = $props($ctl->setup($req($pengawas)), $req($pengawas));
    $ok('  dan layarnya diberi tahu bahwa ia tidak boleh mengubah',
        ($isiLihat['props']['bisaUbah'] ?? true) === false, var_export($isiLihat['props']['bisaUbah'] ?? null, true));

    $kode = 0;
    try { $ctl->update($req($pengawas, $kirimAwal ?? ['banks' => []], 'PUT')); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('hanya-lihat DITOLAK saat menyimpan', $kode === 403, (string) $kode);

    $kode = 0;
    try { $ctl->unggahGambar($req($pengawas, [], 'POST')); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('hanya-lihat DITOLAK saat mengunggah gambar', $kode === 403, (string) $kode);

    echo "\n=== 4. Simpan: dokumen disusun ulang, bukan dipercaya ===\n";

    $kirim = [
        'effectiveMonth' => '  Oktober 2026  ',
        'brandLogo' => '/img/kpr-calculator/brand-logo.png',
        'introPhoto' => '', 'introPhotoMobile' => '',
        'banks' => [[
            'id' => 'ujibank', 'name' => '  UJI Bank  ', 'fullName' => 'Bank Uji Coba',
            'logoImage' => '/img/kpr-calculator/bank-btn-logo.png',
            'plafon' => ['min' => 50000000, 'max' => 900000000],
            'tenor' => ['minYears' => 2, 'maxYears' => 25],
            'dpDefaultPercent' => 7.5, 'dpMinPercent' => 0, 'utjAmount' => 1000000,
            'maxInstallmentRatio' => 0.35,
            'promo' => ['enabled' => true, 'slide1Text' => ' Promo *UJI* ', 'slide2Text' => '', 'slide2Image' => ''],
            'schemes' => [
                // Skema fixed_floating yang MASIH MEMBAWA tiers sisa tipe lama.
                ['id' => 'ff', 'type' => 'fixed_floating', 'label' => 'Fixed lalu Floating',
                    'fixedYears' => 3, 'fixedRatePercent' => 4.25, 'floatingRatePercent' => 12,
                    'minTenorYears' => 10,
                    'tiers' => [['years' => 3, 'ratePercent' => 5, 'tierLabel' => 'Sisa']]],
                ['id' => 'anu', 'type' => 'anuitas', 'label' => 'Anuitas', 'ratePercent' => 9.25, 'minTenorYears' => 0],
            ],
        ]],
    ];
    $ctl->update($req($user, $kirim, 'PUT'));
    $baru = KprCalculatorSetting::query()->first()->fresh()->config;

    $ok('bulan berlaku dipangkas spasinya', ($baru['effectiveMonth'] ?? '') === 'Oktober 2026', (string) ($baru['effectiveMonth'] ?? ''));
    $ok('nama bank dipangkas spasinya', ($baru['banks'][0]['name'] ?? '') === 'UJI Bank', (string) ($baru['banks'][0]['name'] ?? ''));
    $ok('hanya bank yang dikirim yang tersimpan', count($baru['banks']) === 1, (string) count($baru['banks']));
    $ok('hash kata sandi lama TETAP ada sesudah disimpan', ($baru['adminPasswordHash'] ?? '') === ($cfgDb['adminPasswordHash'] ?? ''),
        'hash hilang saat menyimpan — berkas ekspor jadi tidak kompatibel');
    $ok('lastUpdated diperbarui', ($baru['lastUpdated'] ?? '') !== ($cfgDb['lastUpdated'] ?? ''));
    // Skema fixed_floating tidak boleh menyisakan tiers.
    $ok('field tipe lama DIBUANG saat menyimpan', ! array_key_exists('tiers', $baru['banks'][0]['schemes'][0]),
        'skema fixed_floating masih membawa tiers sisa tipe sebelumnya');
    $ok('field tipe yang benar ikut tersimpan',
        abs((float) $baru['banks'][0]['schemes'][0]['fixedRatePercent'] - 4.25) < 1e-9
        && abs((float) $baru['banks'][0]['schemes'][0]['floatingRatePercent'] - 12) < 1e-9);
    $ok('anuitas hanya membawa ratePercent',
        array_key_exists('ratePercent', $baru['banks'][0]['schemes'][1])
        && ! array_key_exists('fixedYears', $baru['banks'][0]['schemes'][1]));
    $ok('tenor minimum khusus skema tersimpan', (int) $baru['banks'][0]['schemes'][0]['minTenorYears'] === 10);
    $ok('promo tersimpan & teksnya dipangkas', ($baru['banks'][0]['promo']['slide1Text'] ?? '') === 'Promo *UJI*',
        (string) ($baru['banks'][0]['promo']['slide1Text'] ?? ''));

    echo "\n=== 5. Nilai gambar yang tidak dipercaya ===\n";

    foreach ([
        'javascript:alert(1)',
        'https://situs-lain.example/logo.png',
        '../../../../etc/passwd',
        'data:image/png;base64,AAAA',
        '/img/kpr-calculator/../../.env',
    ] as $jahat) {
        $k = $kirim;
        $k['banks'][0]['logoImage'] = $jahat;
        $ctl->update($req($user, $k, 'PUT'));
        $nilai = KprCalculatorSetting::query()->first()->fresh()->config['banks'][0]['logoImage'] ?? null;
        $ok('ditolak jadi kosong: '.substr($jahat, 0, 34), $nilai === '', var_export($nilai, true));
    }

    // Yang sah harus TETAP diterima — penjaga yang menolak semuanya sama saja dengan
    // mematikan fitur unggah gambar.
    $k = $kirim;
    $k['banks'][0]['logoImage'] = '/img/kpr-calculator/bank-bri-logo.jpg';
    $ctl->update($req($user, $k, 'PUT'));
    $ok('path bawaan yang sah TETAP diterima',
        (KprCalculatorSetting::query()->first()->fresh()->config['banks'][0]['logoImage'] ?? '') === '/img/kpr-calculator/bank-bri-logo.jpg');

    // URL berkas unggahan harus bolak-balik utuh (layar mengirim balik URL, bukan path simpanan).
    $urlUnggahan = route('kpr.kalkulator.gambar', ['berkas' => 'abc123.png']);
    $k['banks'][0]['logoImage'] = $urlUnggahan;
    $ctl->update($req($user, $k, 'PUT'));
    $ok('URL berkas unggahan kembali jadi path simpanannya',
        (KprCalculatorSetting::query()->first()->fresh()->config['banks'][0]['logoImage'] ?? '') === 'unggahan/abc123.png',
        (string) (KprCalculatorSetting::query()->first()->fresh()->config['banks'][0]['logoImage'] ?? ''));
    $isiUlang = $props($ctl->setup($req($user)), $req($user));
    $ok('  dan dikirim balik ke layar sebagai URL utuh',
        ($isiUlang['props']['config']['banks'][0]['logoImage'] ?? '') === $urlUnggahan,
        (string) ($isiUlang['props']['config']['banks'][0]['logoImage'] ?? ''));

    echo "\n=== 6. Validasi ===\n";

    $tolak = function (array $ubah, string $judul) use ($ctl, $req, $user, $ok, $kirim) {
        $k = $kirim;
        foreach ($ubah as $jalur => $nilai) {
            $bagian = explode('.', $jalur);
            $ref = &$k;
            foreach ($bagian as $b) { $ref = &$ref[is_numeric($b) ? (int) $b : $b]; }
            $ref = $nilai;
            unset($ref);
        }
        $ditolak = false;
        try { $ctl->update($req($user, $k, 'PUT')); } catch (ValidationException $e) { $ditolak = true; }
        $ok($judul, $ditolak, 'nilai tidak masuk akal ini diterima');
    };

    $tolak(['banks' => []], 'daftar bank kosong ditolak');
    $tolak(['banks.0.schemes' => []], 'bank tanpa skema ditolak');
    $tolak(['banks.0.schemes.0.type' => 'apa_saja'], 'tipe skema asing ditolak');
    $tolak(['banks.0.schemes.0.floatingRatePercent' => 250], 'bunga 250% ditolak');
    $tolak(['banks.0.schemes.0.floatingRatePercent' => -3], 'bunga negatif ditolak');
    $tolak(['banks.0.tenor.maxYears' => 99], 'tenor 99 tahun ditolak');
    $tolak(['banks.0.dpDefaultPercent' => 120], 'DP 120% ditolak');
    $tolak(['banks.0.maxInstallmentRatio' => 5], 'rasio angsuran 500% ditolak');
    $tolak(['banks.0.id' => 'bank spasi/aneh'], 'kode bank dengan karakter aneh ditolak');
    $tolak(['banks.0.name' => ''], 'nama bank kosong ditolak');

    echo "\n=== 7. Sajian berkas gambar unggahan ===\n";

    Storage::disk('local')->put($berkasUji, "\x89PNG\r\n\x1a\n uji");
    $namaSaja = basename($berkasUji);

    $res = $ctl->gambar($req($user, [], 'GET', '/kpr/kalkulator/gambar/'.$namaSaja), $namaSaja);
    $ok('berkas yang ada tersaji', $res->getStatusCode() === 200, (string) $res->getStatusCode());
    $ok('tipe kontennya image/png', str_contains((string) $res->headers->get('Content-Type'), 'image/png'),
        (string) $res->headers->get('Content-Type'));

    foreach (['../../.env', '..%2F..%2F.env', 'tanpa-titik', 'a/b.png'] as $jahat) {
        $kode = 0;
        try { $ctl->gambar($req($user, [], 'GET', '/x'), $jahat); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
        $ok('nama berkas tidak sah ditolak: '.$jahat, $kode === 404, (string) $kode);
    }
    $kode = 0;
    try { $ctl->gambar($req($user, [], 'GET', '/x'), 'tidakada0000.png'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('berkas tidak ada → 404', $kode === 404, (string) $kode);

    $kode = 0;
    try { $ctl->gambar($req($polos, [], 'GET', '/x'), $namaSaja); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('tanpa izin → gambar ditolak', $kode === 403, (string) $kode);

    echo "\n=== 8. Unduh config.js ===\n";

    $eks = $ctl->ekspor($req($user));
    $teks = (string) $eks->getContent();
    $ok('bertipe javascript', str_contains((string) $eks->headers->get('Content-Type'), 'javascript'));
    $ok('diunduh sebagai config.js', str_contains((string) $eks->headers->get('Content-Disposition'), 'config.js'));
    $ok('berisi const KPR_CONFIG', str_contains($teks, 'const KPR_CONFIG = {'));
    $json = trim(substr($teks, strpos($teks, 'const KPR_CONFIG = ') + 19));
    $json = rtrim($json, ";\n\r ");
    $urai = json_decode($json, true);
    $ok('isinya JSON yang sah', is_array($urai), json_last_error_msg());
    $ok('membawa hash kata sandi (dipakai aplikasi aslinya)', ($urai['adminPasswordHash'] ?? '') !== '');

    echo "\n=== 9. Rute terdaftar ===\n";

    foreach ([
        'kpr.setup.perhitungan.index'  => 'setup',
        'kpr.setup.perhitungan.update' => 'update',
        'kpr.setup.perhitungan.gambar' => 'unggahGambar',
        'kpr.setup.perhitungan.ekspor' => 'ekspor',
        'kpr.kalkulator.index'         => 'kalkulator',
        'kpr.kalkulator.gambar'        => 'gambar',
    ] as $nama => $aksi) {
        $rute = \Illuminate\Support\Facades\Route::getRoutes()->getByName($nama);
        $ok("rute $nama → $aksi", $rute !== null && str_contains((string) $rute->getActionName(), 'KprCalculatorController@'.$aksi),
            (string) $rute?->getActionName());
    }

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
} catch (\Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
    // Berkas TIDAK ikut rollback — dibersihkan sendiri.
    if (Storage::disk('local')->exists($berkasUji)) {
        Storage::disk('local')->delete($berkasUji);
    }
}
