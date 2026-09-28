<?php

/**
 * Actual Costing COGS mendapat DUA sumber otomatis:
 *
 *   1. KONTRAK SPK (MUK) yang ditautkan lewat tombol "Link COGS"
 *   2. MUTASI BUKU BESAR pada akun COA yang ditandai Komponen COGS di Tambah/Edit Akun
 *
 * Pemetaannya, persis seperti yang diminta:
 *     Tanah → Actual Costing COGS-Tanah · Housing → -Housing
 *     Infrastruktur → -Infra            · License Permit → -License
 *
 * ┌─ YANG PALING MENENTUKAN ──────────────────────────────────────────────────────────┐
 * │ Chart of Account memakai kunci HPP (tanah·bangunan·sarana·permit), COGS Baru       │
 * │ memakai kunci sendiri (tanah·housing·infra·license). DUA KOSAKATA untuk empat hal  │
 * │ yang sama. Satu terjemahan yang terlupa menghasilkan komponen yang diam-diam       │
 * │ kosong — bukan galat. Pemetaannya karena itu hanya boleh ada di SATU tempat, dan   │
 * │ uji ini menuntut keempat pasangannya menyeberang dengan benar.                     │
 * └───────────────────────────────────────────────────────────────────────────────────┘
 *
 * Yang juga dijaga, berurut dari yang paling mahal:
 *
 *  1. AKUN COA HARUS DIAKUI PER PERIODE. Tandanya melekat pada AKUN, sedangkan bagan akun di
 *     sini memisahkan cluster di tingkat akun (CIP TANAH C3 KRIAN dan CIP TANAH C5 SUKODONO
 *     sama-sama bertanda Tanah). Akun yang tidak dicentang TIDAK BOLEH membebani periode ini —
 *     kalau ikut, biaya cluster lain masuk ke HPP unit cluster ini, angkanya tetap keluar, dan
 *     tidak ada satu pun galat.
 *  2. AKUN YANG DISELUNDUPKAN DITOLAK. Hanya akun yang benar-benar bertanda komponen itu yang
 *     boleh diakui, apa pun yang dikirim layar.
 *  3. YANG DIBEBANKAN DARI KONTRAK ADALAH NILAI KONTRAK, bukan yang sudah ditagih. Tujuan layar
 *     ini adalah mengetahui KESELURUHAN COGS supaya pembebanan ke tiap unit tepat; memakai yang
 *     sudah ditagih membuat HPP per unit bergerak tiap ada tagihan baru, sehingga unit yang
 *     di-booked lebih dulu terkunci pada angka yang lebih kecil semata-mata karena waktu
 *     penagihan. "Diopname" & "Sudah Ditagih" tetap tampil sebagai keterangan, TIDAK dijumlah.
 *  4. JURNAL YANG BELUM 'posted' TAMPIL TETAPI TIDAK DIJUMLAHKAN, dan kredit menjadi
 *     PENGURANGAN — akun CIP juga dikredit oleh proses HPP itu sendiri.
 *  5. SAKLAR SUMBER BENAR-BENAR MEMATIKAN sumbernya, dan rekonsiliasi tetap tertutup pada
 *     keadaan mana pun — Σ realisasi + Σ terkunci = biaya aktual, PERSIS.
 *  6. KATEGORI hanya disimpan untuk komponen yang memang memakainya (Housing/Infra/License);
 *     pada Tanah ia dikosongkan, bukan disimpan diam-diam sebagai data yang tak pernah dibaca.
 *  7. Pilihan kategori pada modal Link COGS datang dari SET UP KATEGORI COGS.
 *
 * Seluruh tulisan dibungkus transaksi lalu DIBATALKAN.
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-cogs-baru-sumber.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCogs2Akun;
use App\Domain\BusinessSupport\Models\BsCogs2HousingTipe;
use App\Domain\BusinessSupport\Models\BsCogs2Kategori;
use App\Domain\BusinessSupport\Models\BsCogs2Periode;
use App\Domain\BusinessSupport\Models\BsCogs2Unit;
use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsMukContractCogs;
use App\Domain\BusinessSupport\Services\Cogs2Sumber;
use App\Http\Controllers\Web\BusinessSupport\Cogs\Cogs2Controller as K;
use App\Http\Controllers\Web\BusinessSupport\Payable\MukKontrakController as M;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$lulus = 0;
$gagal = 0;
$tuntas = false;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
$sama = fn ($a, $b, float $eps = 0.005) => $a !== null && abs((float) $a - (float) $b) < $eps;

$user = \App\Models\User::all()->first(fn ($u) => $u->hasRole('SuperAdmin')) ?? \App\Models\User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);
$tenant = (int) $user->tenant_id;
$TAG = 'ZSB'.strtoupper(substr((string) getmypid(), -4));

$ctl = app(K::class);
$req = function (string $metode, array $data = []) {
    $r = \Illuminate\Http\Request::create('/uji', $metode, $data);
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $r->setUserResolver(fn () => auth()->user());

    return $r;
};
$props = fn ($resp) => json_decode((string) $resp->toResponse(\Illuminate\Http\Request::create('/uji', 'GET', [], [], [], [
    'HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => (string) \Inertia\Inertia::getVersion(),
]))->getContent(), true)['props'] ?? [];

DB::beginTransaction();

try {
    foreach (['bs_cogs2_akun'] as $t) {
        $ok('prasyarat: tabel '.$t.' ada', Schema::hasTable($t), 'jalankan migrasi 2026_09_28_100000_link_cogs2_sumber.php');
    }
    $ok('prasyarat: kolom kategori pada tautan MUK ada', Schema::hasColumn('bs_muk_contract_cogs', 'kategori'));
    $ok('prasyarat: saklar sumber pada periode ada',
        Schema::hasColumn('bs_cogs2_periode', 'ikut_muk') && Schema::hasColumn('bs_cogs2_periode', 'ikut_gl'));

    echo "\n=== 1. Pemetaan kosakata komponen ===\n";

    // Inilah yang menyeberangkan Chart of Account ke COGS Baru. Empat pasangan, persis permintaan.
    foreach ([['tanah', 'tanah'], ['bangunan', 'housing'], ['sarana', 'infra'], ['permit', 'license']] as [$hpp, $c2]) {
        $ok('  '.$hpp.' (COA) → '.$c2.' (COGS Baru)', Cogs2Sumber::dariHpp($hpp) === $c2);
        $ok('    dan sebaliknya', Cogs2Sumber::keHpp($c2) === $hpp);
    }
    $ok('  kunci tak dikenal memulangkan null, bukan menebak', Cogs2Sumber::dariHpp('entah') === null
        && Cogs2Sumber::dariHpp(null) === null && Cogs2Sumber::keHpp('entah') === null);
    // Tanah tidak berkolom kategori di Actual Costing — di lampiran pun tidak.
    $ok('  hanya Housing/Infra/License yang berkategori',
        ! Cogs2Sumber::perluKategori('tanah') && Cogs2Sumber::perluKategori('housing')
        && Cogs2Sumber::perluKategori('infra') && Cogs2Sumber::perluKategori('license'));

    // ── Fikstur ───────────────────────────────────────────────────────────────────
    $p = BsCogs2Periode::create([
        'tenant_id' => $tenant, 'lokasi_id' => 991001, 'lokasi_kode' => 'JRK3',
        'lokasi_nama' => 'LOKASI UJI SUMBER', 'tahap' => '01',
        'luas_lahan' => 1_000, 'saleable' => 400, 'rth' => 300, 'luas_bangunan' => 200,
        'jumlah_unit' => 4, 'ikut_muk' => true, 'ikut_gl' => true,
    ]);
    BsCogs2HousingTipe::create(['tenant_id' => $tenant, 'periode_id' => $p->id,
        'nama' => 'TIPE-'.$TAG, 'luas_bangunan' => 50, 'urut' => 1]);
    foreach (range(1, 4) as $i) {
        BsCogs2Unit::create(['tenant_id' => $tenant, 'periode_id' => $p->id,
            'unit_rumah' => $TAG.'-'.$i, 'tipe' => 'TIPE-'.$TAG, 'lt' => 100, 'lb' => 50,
            'status' => 'belum', 'costing' => 'belum', 'urut' => $i]);
    }
    // Satu kategori Housing supaya pilihan kategori pada modal Link COGS ada isinya.
    BsCogs2Kategori::create(['tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => 'housing',
        'kategori' => 'KAT-'.$TAG, 'no_spk' => 'SPK/'.$TAG, 'nominal' => 1_000_000, 'urut' => 1]);

    echo "\n=== 2. Kontrak SPK (MUK) → Actual Costing ===\n";

    $mukId = 970000 + (int) substr((string) getmypid(), -4);
    DB::table('bs_muk_contracts')->insert([
        'tenant_id' => $tenant, 'muk_id' => $mukId, 'no_kontrak' => 'NK-'.$TAG, 'no_spk' => 'SPK/'.$TAG,
        'jenis' => 'kontrak', 'status' => 'approved', 'proyek_kode' => '062', 'proyek_nama' => 'PROYEK UJI',
        'tanggal' => '2026-06-05', 'nilai_kontrak' => 900_000_000,
        'kum_ditagih_neto' => 300_000_000, 'kum_diopname' => 450_000_000,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $kontrak = BsMukContract::where('muk_id', $mukId)->firstOrFail();

    $mCtl = app(M::class);
    $mReq = function (array $data) {
        $r = \Illuminate\Http\Request::create('/x', 'PUT', $data);
        $r->setUserResolver(fn () => auth()->user());

        return $r;
    };
    // Housing berkategori — pilihannya datang dari Set Up Kategori COGS.
    $mCtl->simpanCogs($mReq(['component' => 'bangunan', 'kategori' => 'KAT-'.$TAG]), $kontrak);
    $tautan = BsMukContractCogs::where('muk_id', $mukId)->first();
    $ok('tautan tersimpan beserta kategorinya', $tautan?->component === 'bangunan' && $tautan?->kategori === 'KAT-'.$TAG);

    $h = $props($ctl->actual($req('GET'), $p, 'housing'));
    $mukBaris = $h['sumberMuk']['housing'] ?? [];
    $ok('kontrak muncul di Actual Costing COGS-Housing', count($mukBaris) === 1);
    // Fikstur sengaja memberi tiga angka yang BERBEDA (kontrak 900 jt, ditagih 300 jt, opname
    // 450 jt) supaya tidak ada dua di antaranya yang bisa tertukar tanpa terlihat.
    $ok('  yang dibebankan NILAI KONTRAK (900 jt), bukan yang sudah ditagih (300 jt)',
        $sama($mukBaris[0]['penambahan'] ?? null, 900_000_000),
        'penambahan='.($mukBaris[0]['penambahan'] ?? 'tidak ada')
        .' — yang sudah ditagih membuat HPP per unit bergerak tiap ada tagihan baru');
    $ok('  Diopname & Sudah Ditagih tampil sebagai keterangan, TIDAK dijumlah',
        $sama($mukBaris[0]['ditagih'] ?? null, 300_000_000)
        && $sama($mukBaris[0]['diopname'] ?? null, 450_000_000),
        'ditagih='.($mukBaris[0]['ditagih'] ?? 'tidak ada').' opname='.($mukBaris[0]['diopname'] ?? 'tidak ada'));
    $ok('  kategorinya terbawa', ($mukBaris[0]['kategori'] ?? null) === 'KAT-'.$TAG);
    $ok('  dan TIDAK muncul di komponen lain',
        ($h['sumberMuk']['tanah'] ?? []) === [] && ($h['sumberMuk']['infra'] ?? []) === []);
    $ok('  biaya aktual Housing = 900 jt (nilai kontrak)', $sama($h['hitung']['rekon']['housing']['aktual'] ?? null, 900_000_000));
    $ok('  rekonsiliasinya tertutup', $sama($h['hitung']['rekon']['housing']['selisih'] ?? null, 0));

    // Kategori pada komponen yang TIDAK memakainya harus dikosongkan, bukan disimpan diam-diam.
    $mCtl->simpanCogs($mReq(['component' => 'tanah', 'kategori' => 'KAT-'.$TAG]), $kontrak);
    $ok('kategori dibuang saat komponennya Tanah (tidak berkategori)',
        BsMukContractCogs::where('muk_id', $mukId)->value('kategori') === null,
        'kategori pada komponen yang tidak memakainya tidak pernah dibaca siapa pun');
    $mCtl->simpanCogs($mReq(['component' => 'bangunan', 'kategori' => 'KAT-'.$TAG]), $kontrak);

    echo "\n=== 3. Pilihan kategori pada modal Link COGS ===\n";

    $pm = $props($mCtl->index($req('GET')));
    $ok('pilihan kategori datang dari Set Up Kategori COGS',
        in_array('KAT-'.$TAG, $pm['kategoriOpsi']['bangunan'] ?? [], true),
        'opsi: '.json_encode($pm['kategoriOpsi'] ?? null));
    $ok('  Tanah tidak menawarkan kategori', ($pm['perluKategori']['tanah'] ?? null) === false
        && ($pm['kategoriOpsi']['tanah'] ?? null) === []);
    $ok('  Housing/Infra/License menawarkan kategori',
        ($pm['perluKategori']['bangunan'] ?? null) === true
        && ($pm['perluKategori']['sarana'] ?? null) === true
        && ($pm['perluKategori']['permit'] ?? null) === true);

    echo "\n=== 4. COA bertanda komponen → Actual Costing ===\n";

    // Dua akun bertanda Tanah: satu diakui periode ini, satu TIDAK. Inilah inti penjaganya.
    $induk = BsAccount::where('level', 5)->where('is_active', true)->first();
    if ($induk === null) { throw new \RuntimeException('Tidak ada akun level 5 untuk dijadikan induk.'); }
    $buatAkun = function (string $kode, string $nama) use ($tenant, $induk) {
        return BsAccount::create([
            'tenant_id' => $tenant, 'parent_id' => $induk->id, 'level' => 6,
            'code' => $kode, 'name' => $nama, 'type' => $induk->type, 'normal_balance' => 'D',
            'is_postable' => true, 'is_active' => true, 'cogs_component' => 'tanah',
        ]);
    };
    $akunIkut = $buatAkun(substr($induk->code.'91', 0, 11), 'CIP TANAH IKUT '.$TAG);
    $akunLuar = $buatAkun(substr($induk->code.'92', 0, 11), 'CIP TANAH LUAR '.$TAG);

    $buatJurnal = function (string $no, string $status, int $akunId, float $debit, float $kredit) use ($tenant) {
        $jid = DB::table('bs_journals')->insertGetId([
            'tenant_id' => $tenant, 'journal_number' => $no, 'journal_date' => '2026-06-10',
            'period_year' => 2026, 'period_month' => 6, 'source' => 'manual',
            'description' => 'Uji sumber', 'status' => $status,
            'total_debit' => $debit, 'total_credit' => $kredit,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bs_journal_lines')->insert([
            'journal_id' => $jid, 'account_id' => $akunId, 'description' => 'baris uji',
            'debit' => $debit, 'credit' => $kredit, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $buatJurnal('JU-'.$TAG.'-1', 'posted', $akunIkut->id, 500_000_000, 0);
    $buatJurnal('JU-'.$TAG.'-2', 'posted', $akunIkut->id, 0, 50_000_000);   // kredit → pengurangan
    $buatJurnal('JU-'.$TAG.'-3', 'final', $akunIkut->id, 777_000_000, 0);   // belum efektif
    $buatJurnal('JU-'.$TAG.'-4', 'posted', $akunLuar->id, 999_000_000, 0);  // akun cluster lain

    // Belum ada akun yang diakui → belum ada yang membebani.
    $h0 = $props($ctl->actual($req('GET'), $p, 'tanah'));
    $ok('sebelum akun diakui, buku besar belum membebani apa pun',
        ($h0['sumberGl']['tanah'] ?? []) === [] && $sama($h0['hitung']['rekon']['tanah']['aktual'] ?? null, 0));
    $daftar = collect($h0['akunBertanda'] ?? [])->pluck('id')->all();
    $ok('  tetapi kedua akun bertanda SUDAH tampil untuk dipilih',
        in_array($akunIkut->id, $daftar, true) && in_array($akunLuar->id, $daftar, true));

    // Akui hanya SATU akun.
    $ctl->simpanAkun($req('PUT', ['account_id' => [$akunIkut->id]]), $p, 'tanah');
    $h1 = $props($ctl->actual($req('GET'), $p, 'tanah'));
    $gl = $h1['sumberGl']['tanah'] ?? [];
    $ok('akun yang dicentang membebani periode ini', count($gl) === 3,
        'baris='.count($gl).' — tiga jurnal pada akun itu, termasuk yang belum efektif');
    $ok('  akun yang TIDAK dicentang tidak ikut sama sekali',
        collect($gl)->every(fn ($b) => ! str_contains((string) $b['akun'], 'LUAR')),
        'biaya cluster lain yang ikut akan masuk ke HPP unit cluster ini tanpa satu pun galat');
    // 500 jt debit − 50 jt kredit = 450 jt; jurnal 'final' TIDAK dijumlahkan.
    $ok('  debit → penambahan, kredit → pengurangan, "final" tidak dijumlah',
        $sama($h1['hitung']['rekon']['tanah']['aktual'] ?? null, 450_000_000),
        'aktual='.($h1['hitung']['rekon']['tanah']['aktual'] ?? 'tidak ada').' — harap 450.000.000');
    $ok('  baris yang belum efektif TETAP tampil dengan penandanya',
        collect($gl)->contains(fn ($b) => $b['efektif'] === false && $b['status'] === 'final'),
        'membuangnya membuat jumlah layar berhenti sama dengan mutasi akunnya');
    $ok('  rekonsiliasinya tetap tertutup', $sama($h1['hitung']['rekon']['tanah']['selisih'] ?? null, 0));

    // Akun yang tidak bertanda komponen ini tidak boleh diselundupkan lewat kiriman.
    $akunNetral = BsAccount::whereNull('cogs_component')->where('is_postable', true)->first();
    $ctl->simpanAkun($req('PUT', ['account_id' => [$akunIkut->id, $akunNetral->id]]), $p, 'tanah');
    $ok('akun tanpa tanda komponen DITOLAK diakui',
        BsCogs2Akun::where('periode_id', $p->id)->pluck('account_id')->all() === [$akunIkut->id],
        'tanpa penyaring ini, akun mana pun bisa diselundupkan dan mutasinya membebani HPP');

    echo "\n=== 5. Saklar sumber ===\n";

    $ctl->simpanSumber($req('PUT', ['ikut_muk' => false, 'ikut_gl' => true]), $p);
    $h2 = $props($ctl->actual($req('GET'), $p, 'housing'));
    $ok('mematikan sumber MUK menghilangkan pembebanannya',
        ($h2['sumberMuk']['housing'] ?? []) === []
        && $sama($h2['hitung']['rekon']['housing']['aktual'] ?? null, 0));
    $ok('  dan buku besar tetap membebani Tanah',
        $sama($h2['hitung']['rekon']['tanah']['aktual'] ?? null, 450_000_000));

    $ctl->simpanSumber($req('PUT', ['ikut_muk' => true, 'ikut_gl' => false]), $p);
    $h3 = $props($ctl->actual($req('GET'), $p, 'tanah'));
    $ok('mematikan sumber buku besar menghilangkan pembebanannya',
        ($h3['sumberGl']['tanah'] ?? []) === []
        && $sama($h3['hitung']['rekon']['tanah']['aktual'] ?? null, 0));
    $ok('  dan MUK tetap membebani Housing',
        $sama($h3['hitung']['rekon']['housing']['aktual'] ?? null, 900_000_000));

    $ctl->simpanSumber($req('PUT', ['ikut_muk' => true, 'ikut_gl' => true]), $p);

    echo "\n=== 6. Ketiga sumber berdampingan ===\n";

    // Satu baris yang diketik sendiri, di samping kedua sumber otomatis.
    \App\Domain\BusinessSupport\Models\BsCogs2Actual::create([
        'tenant_id' => $tenant, 'periode_id' => $p->id, 'komponen' => 'tanah',
        'no_jurnal' => 'MANUAL-'.$TAG, 'keterangan' => 'diketik sendiri',
        'penambahan' => 100_000_000, 'pengurangan' => 0, 'urut' => 1,
    ]);
    $h4 = $props($ctl->actual($req('GET'), $p, 'tanah'));
    $sub = $h4['subtotal']['tanah'] ?? [];
    $ok('subtotal ketiga sumber disebut terpisah',
        $sama($sub['manual'] ?? null, 100_000_000) && $sama($sub['gl'] ?? null, 450_000_000)
        && $sama($sub['muk'] ?? null, 0),
        'subtotal='.json_encode($sub).' — satu angka besar menyembunyikan penggandaan');
    $ok('  biaya aktual = jumlah ketiganya', $sama($h4['hitung']['rekon']['tanah']['aktual'] ?? null, 550_000_000));
    $ok('  rekonsiliasinya tetap tertutup', $sama($h4['hitung']['rekon']['tanah']['selisih'] ?? null, 0));
    // Empat unit berluas tanah 100 → tarif 550 jt / 400 m² = 1.375.000 per m².
    $ok('  dan benar-benar sampai ke HPP per unit',
        $sama($h4['hitung']['rekon']['tanah']['tarif'] ?? null, 1_375_000)
        && $sama($h4['hitung']['unit'][0]['realisasi']['tanah'] ?? null, 137_500_000),
        'tarif='.($h4['hitung']['rekon']['tanah']['tarif'] ?? 'tidak ada'));

    echo "\n=== 7. Penjaga izin & rute ===\n";

    foreach (['bs.cogs2.akun.simpan', 'bs.cogs2.sumber.simpan'] as $nama) {
        $ok('rute '.$nama.' terdaftar', app('router')->getRoutes()->getByName($nama) !== null);
    }
    $pengguna = fn (array $izin) => new class($izin)
    {
        public int $id = 0;

        public string $name = 'Penguji';

        public function __construct(private array $izin) {}

        public function can($per, $arguments = []): bool { return in_array((string) $per, $this->izin, true); }
    };
    foreach ([['simpanAkun', ['tanah']], ['simpanSumber', []]] as [$metode, $ekstra]) {
        $status = null;
        try {
            $r = \Illuminate\Http\Request::create('/x', 'PUT', ['account_id' => [], 'ikut_muk' => true, 'ikut_gl' => true]);
            $r->setUserResolver(fn () => $pengguna(['cogs.budget.view']));
            $ctl->{$metode}($r, $p, ...$ekstra);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $status = $e->getStatusCode(); }
        $ok('izin BACA saja ditolak '.$metode.' (403)', $status === 403, 'hasil: '.var_export($status, true));
    }
    $ok('tidak membuat izin baru',
        \Spatie\Permission\Models\Permission::where('name', 'like', 'cogs2%')->count() === 0);

    echo "\n=== 8. Perkabelan layar ===\n";

    $muk = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Payable/MukKontrak/Index.vue'));
    $ok('modal Link COGS punya pemilih kategori', str_contains($muk, 'Kategori pada Actual Costing COGS'));
    $ok('  hanya tampil saat komponennya memang berkategori', str_contains($muk, 'v-if="butuhKategori"'));
    $ok('  berganti komponen membuang kategori lama', str_contains($muk, 'watch(pilihan'),
        'kategori milik satu komponen tidak pernah berlaku untuk komponen lain');

    $act = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Cogs2/Actual.vue'));
    $ok('layar Actual memuat ketiga sumber', str_contains($act, 'Sumber Biaya Aktual')
        && str_contains($act, 'Dari Kontrak SPK (MUK)') && str_contains($act, 'Dari Buku Besar'));
    $ok('  pemilih akun COA ada', str_contains($act, 'Akun COA bertanda'));
    // Keterangan di layar harus menyebut yang BENAR-BENAR dibebankan. Keterangan yang membantah
    // kodenya sendiri lebih menyesatkan daripada tidak ada keterangan sama sekali: orang
    // membacanya, mempercayainya, lalu mencari sebab selisih di tempat yang salah.
    $ok('  keterangannya menyebut NILAI KONTRAK sebagai yang dibebankan',
        str_contains($act, 'Yang dibebankan adalah <b>Nilai Kontrak</b>')
        && ! str_contains($act, 'Yang dibebankan adalah <b>Sudah Ditagih</b>'));
    $ok('  dan menyebut Diopname & Sudah Ditagih tidak ikut dijumlahkan',
        str_contains($act, 'tidak ikut dijumlahkan'));
    $ok('  penggandaan baris ditandai', str_contains($act, 'terhitung <b>dua kali</b>'),
        'total tetap merekonsiliasi saat digandakan — satu-satunya cara ia terlihat adalah disebut');

    $coa = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Setup/Accounts.vue'));
    $ok('keterangan di Chart of Account menyebut Actual Costing COGS',
        str_contains($coa, 'Actual Costing COGS') && str_contains($coa, 'dicentang pada periode'),
        'tanpa itu, orang menandai akun lalu mengira angkanya sudah masuk HPP padahal belum dicentang');

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
