<?php

/**
 * Tombol "Link COGS" pada Kontrak SPK (MUK) → pembagian proporsional di layar Komponen COGS.
 *
 * SIFAT YANG MENENTUKAN BENAR-TIDAKNYA ANGKANYA:
 *
 *      jumlah seluruh bagian unit  ===  nilai kontraknya, PERSIS
 *
 * Bukan "kira-kira". Membulatkan tiap bagian sendiri-sendiri membuat jumlahnya meleset
 * beberapa rupiah dari nilai kontrak — selisih yang terlalu kecil untuk terlihat dan terlalu
 * nyata untuk dibiarkan, sebab angka itu berakhir di HPP per unit. Karena itu bagian TERAKHIR
 * menyerap sisa pembulatan, dan sifat itu diuji dengan nilai yang sengaja tidak habis dibagi.
 *
 * Yang juga dijaga, berurut dari yang paling mahal:
 *
 *  1. PEMBAGIANNYA TIDAK PERNAH DIKARANG. Kontrak MUK sendiri TIDAK menyimpan unit; bobotnya
 *     datang dari SPK lokal (bs_work_order_units.unit_price) yang dicocokkan lewat nomor SPK.
 *     Kontrak yang nomor SPK-nya tidak ketemu, SPK-nya belum punya unit, atau seluruh nilai
 *     unitnya nol, DITAMPILKAN UTUH dengan alasan tertulis — bukan dibagi rata. Membagi rata
 *     menghasilkan HPP per unit yang terlihat masuk akal, masuk jurnal, dan salahnya tidak
 *     pernah memunculkan galat.
 *  2. SATU KONTRAK HANYA SATU KOMPONEN. Kontrak yang tertaut ke dua komponen menyumbang
 *     nilainya dua kali ke HPP tanpa satu pun galat.
 *  3. TAUTANNYA SELAMAT DARI PENARIKAN ULANG MUK. Disimpan di tabel sendiri berkunci muk_id,
 *     bukan sebagai kolom pada salinan bs_muk_contracts yang barisnya ditimpa tiap sinkron.
 *  4. IZINNYA cogs.component.manage, bukan izin baca daftar kontrak: menautkan kontrak berarti
 *     menambah biaya ke perhitungan HPP.
 *  5. TIDAK DIJUMLAHKAN dengan sumber lain di layar komponen.
 *
 * Seluruh tulisan dibungkus transaksi lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-link-cogs-kontrak-muk.php';"
 */

use App\Domain\BusinessSupport\Models\BsMukContractCogs;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsComponentController as K;
use App\Http\Controllers\Web\BusinessSupport\Payable\MukKontrakController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

$props = function (string $komponen) {
    $r = \Illuminate\Http\Request::create('/uji', 'GET');
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $r->setUserResolver(fn () => auth()->user());

    return json_decode((string) app(K::class)->show($r, $komponen)->toResponse($r)->getContent(), true)['props'] ?? [];
};

$TAG = 'ZLC'.strtoupper(substr((string) getmypid(), -4));

DB::beginTransaction();

try {
    $ok('prasyarat: tabel salinan kontrak MUK ada', Schema::hasTable('bs_muk_contracts'),
        'jalankan migrasi 2026_09_19_000010_create_bs_muk_contracts');
    $ok('prasyarat: tabel tautan komponen ada', Schema::hasTable('bs_muk_contract_cogs'));

    // ── SPK lokal berikut unit-unitnya: INILAH dasar pembaginya ──────────────────────
    $spk = 'SPK/'.$TAG.'/01';
    $woId = (int) DB::table('bs_work_orders')->insertGetId([
        'tenant_id' => $tenant, 'spk_number' => $spk, 'spk_date' => '2026-06-01',
        'supplier_id' => (int) DB::table('bs_suppliers')->value('id'),
        'construction_account_id' => (int) DB::table('bs_accounts')->value('id'),
        'title' => 'SPK uji '.$TAG,
        'contract_value' => 0, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Bobot 1 : 2 : 1 — sengaja dengan nilai kontrak yang TIDAK habis dibagi, supaya sisa
    // pembulatannya nyata dan bisa diuji.
    foreach ([['U-A', 1_000_000, 1], ['U-B', 2_000_000, 2], ['U-C', 1_000_000, 3]] as [$kode, $harga, $seq]) {
        DB::table('bs_work_order_units')->insert([
            'tenant_id' => $tenant, 'work_order_id' => $woId, 'unit_id' => null,
            'unit_code' => $kode, 'unit_price' => $harga, 'seq' => $seq,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Nilainya dipilih supaya pembulatan tiap bagian BENAR-BENAR meleset kalau sisanya tidak
    // dibebankan ke unit terakhir: 1/4 → 250.000,0075 (naik), 1/2 → 500.000,015 (naik). Tanpa
    // penyerapan sisa, jumlahnya menjadi 1.000.000,04 — lebih besar 1 sen dari nilai kontrak.
    // Angka yang kebetulan habis dibagi membuat uji ini tidak membuktikan apa pun.
    $NILAI = 1_000_000.03;
    $mukId = 900000 + (int) substr((string) getmypid(), -4);
    DB::table('bs_muk_contracts')->insert([
        'tenant_id' => $tenant, 'muk_id' => $mukId, 'no_kontrak' => 'NK-'.$TAG, 'no_spk' => $spk,
        'jenis' => 'kontrak', 'status' => 'approved', 'proyek_kode' => '062', 'proyek_nama' => 'PROYEK UJI',
        'pelanggan_nama' => 'PT UJI', 'tanggal' => '2026-06-05', 'nilai_kontrak' => $NILAI,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Kontrak kedua: nomor SPK-nya tidak ada di SPK lokal → tidak punya dasar bagi.
    DB::table('bs_muk_contracts')->insert([
        'tenant_id' => $tenant, 'muk_id' => $mukId + 1, 'no_kontrak' => 'NK-'.$TAG.'-X', 'no_spk' => 'SPK/TIDAK/ADA',
        'jenis' => 'kontrak', 'status' => 'approved', 'proyek_nama' => 'PROYEK TANPA SPK',
        'tanggal' => '2026-06-06', 'nilai_kontrak' => 500_000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    echo "\n=== 1. Menautkan lewat controller ===\n";

    $ctlMuk = app(MukKontrakController::class);
    $kontrak = \App\Domain\BusinessSupport\Models\BsMukContract::where('muk_id', $mukId)->firstOrFail();
    $req = function (array $data) {
        $r = \Illuminate\Http\Request::create('/x', 'PUT', $data);
        $r->setUserResolver(fn () => auth()->user());

        return $r;
    };

    $ctlMuk->simpanCogs($req(['component' => 'bangunan']), $kontrak);
    $ok('tautan tersimpan', BsMukContractCogs::where('muk_id', $mukId)->value('component') === 'bangunan');
    $ok('  nomor kontraknya ikut disalin (tetap terbaca saat salinan MUK kosong)',
        BsMukContractCogs::where('muk_id', $mukId)->value('no_kontrak') === 'NK-'.$TAG);

    // Satu kontrak satu komponen: menautkan ulang MENGGANTI, bukan menambah baris kedua.
    $ctlMuk->simpanCogs($req(['component' => 'sarana']), $kontrak);
    $ok('menautkan ulang mengganti, bukan menggandakan',
        BsMukContractCogs::where('muk_id', $mukId)->count() === 1
        && BsMukContractCogs::where('muk_id', $mukId)->value('component') === 'sarana',
        'kontrak tertaut ke dua komponen — nilainya disumbangkan dua kali ke HPP');
    $ctlMuk->simpanCogs($req(['component' => 'bangunan']), $kontrak);

    $tolak = false;
    try { $ctlMuk->simpanCogs($req(['component' => 'housing']), $kontrak); }
    catch (\Illuminate\Validation\ValidationException $e) { $tolak = array_key_exists('component', $e->errors()); }
    $ok('  slug alamat ("housing") ditolak sebagai nilai simpan', $tolak);

    echo "\n=== 2. Pembagian proporsional ===\n";

    $d = $props('housing');   // slug 'housing' → komponen HPP 'bangunan'
    $baris = collect($d['mukBaris'] ?? [])->where('kontrak', 'NK-'.$TAG)->values();

    $ok('kontrak tertaut muncul di layar komponennya', $baris->isNotEmpty());
    $ok('  dibagi ke TIGA unit SPK-nya', $baris->count() === 3,
        'bagian: '.$baris->count());
    $ok('  urutannya mengikuti seq unit',
        $baris->pluck('unit')->all() === ['U-A', 'U-B', 'U-C']);

    // Bobot 1:2:1 dari 1.000.000,01
    $ok('  porsi unit B dua kali unit A',
        abs((float) $baris[1]['porsi'] - 2 * (float) $baris[0]['porsi']) < 0.000001);
    $ok('  bagian unit A = 250.000,01 (dibulatkan ke atas)', abs((float) $baris[0]['nilai'] - 250_000.01) < 0.005,
        'A='.$baris[0]['nilai']);
    $ok('  bagian unit B = 500.000,02 (dibulatkan ke atas)', abs((float) $baris[1]['nilai'] - 500_000.02) < 0.005,
        'B='.$baris[1]['nilai']);

    // INI sifat yang paling menentukan.
    $jumlah = round((float) $baris->sum('nilai'), 2);
    $ok('JUMLAH seluruh bagian === nilai kontrak, PERSIS', abs($jumlah - $NILAI) < 0.005,
        'jumlah='.$jumlah.' nilai kontrak='.$NILAI.' — sisa pembulatan tidak dibebankan ke unit terakhir');
    // Kalau tiap bagian dibulatkan sendiri-sendiri, unit terakhir menerima 250.000,01 dan
    // jumlahnya menjadi 1.000.000,04. Unit terakhir HARUS menerima 250.000,00 — sisanya, bukan
    // porsinya sendiri.
    $ok('  unit terakhir menyerap sisanya (250.000,00, bukan 250.000,01)',
        abs((float) $baris[2]['nilai'] - 250_000.00) < 0.005,
        'C='.$baris[2]['nilai']);

    echo "\n=== 3. Kontrak tanpa dasar bagi tidak dikarang ===\n";

    $kontrak2 = \App\Domain\BusinessSupport\Models\BsMukContract::where('muk_id', $mukId + 1)->firstOrFail();
    $ctlMuk->simpanCogs($req(['component' => 'bangunan']), $kontrak2);
    $d2 = $props('housing');
    $tanpa = collect($d2['mukBaris'] ?? [])->where('kontrak', 'NK-'.$TAG.'-X')->values();

    $ok('tetap ditampilkan, tidak hilang', $tanpa->count() === 1,
        'kontrak tanpa dasar bagi lenyap — uangnya hilang dari layar tanpa tanda');
    $ok('  utuh sebesar nilai kontraknya', abs((float) $tanpa[0]['nilai'] - 500_000) < 0.005);
    $ok('  TIDAK dibagi rata ke unit mana pun', $tanpa[0]['unit'] === null && $tanpa[0]['porsi'] === null,
        'pembaginya dikarang — HPP per unit terlihat masuk akal tetapi salah');
    $ok('  alasannya tertulis', str_contains((string) $tanpa[0]['alasan'], 'tidak ditemukan'),
        'alasan: '.($tanpa[0]['alasan'] ?? 'tidak ada'));
    $ok('  jumlahnya dilaporkan di ringkasan', ($d2['mukRingkas']['tanpaDasar'] ?? null) === 1);

    echo "\n=== 4. Tidak bocor ke komponen lain & tidak dijumlahkan ===\n";

    $lain = $props('tanah');
    $ok('kontrak bertaut Housing tidak muncul di Tanah',
        collect($lain['mukBaris'] ?? [])->where('kontrak', 'NK-'.$TAG)->isEmpty());

    $ok('blok kontrak MUK dikirim di prop terpisah',
        array_key_exists('mukBaris', $d) && array_key_exists('mukRingkas', $d));
    $refMuk = new ReflectionMethod(K::class, 'dataMuk');
    $refMuk->setAccessible(true);
    $kunci = array_keys($refMuk->invoke(app(K::class), ['cogs' => 'bangunan'], ['tahun' => null, 'search' => '']));
    $ok('  seluruh kuncinya berawalan "muk" (tidak menimpa sumber lain)',
        $kunci !== [] && collect($kunci)->every(fn ($k) => str_starts_with($k, 'muk')),
        'kunci: '.implode(', ', $kunci));

    echo "\n=== 5. Melepas tautan ===\n";

    $ctlMuk->simpanCogs($req(['component' => null]), $kontrak);
    $ok('tautan bisa dilepas', BsMukContractCogs::where('muk_id', $mukId)->count() === 0);
    $ok('  dan kontraknya hilang dari layar komponen',
        collect($props('housing')['mukBaris'] ?? [])->where('kontrak', 'NK-'.$TAG)->isEmpty());

    echo "\n=== 6. Perkabelan layar & izin ===\n";

    $rute = app('router')->getRoutes()->getByName('bs.muk.kontrak.cogs');
    $ok('rute penautan terdaftar', $rute !== null && in_array('PUT', $rute->methods(), true));

    // Diuji lewat PERILAKU, bukan dengan mencari teks "cogs.component.manage" di berkasnya:
    // kata itu juga muncul di index() untuk menyalakan/mematikan tombol, sehingga penjaga di
    // simpanCogs() bisa dilonggarkan tanpa membuat pencarian teks mana pun gagal.
    $pengguna = function (array $izin) {
        return new class($izin)
        {
            public int $id = 0;

            public string $name = 'Penguji';

            public function __construct(private array $izin) {}

            public function can($p, $arguments = []): bool { return in_array((string) $p, $this->izin, true); }
        };
    };
    $panggil = function (object $u) use ($ctlMuk, $kontrak) {
        $r = \Illuminate\Http\Request::create('/x', 'PUT', ['component' => 'bangunan']);
        $r->setUserResolver(fn () => $u);

        return $ctlMuk->simpanCogs($r, $kontrak);
    };

    $status = null;
    try { $panggil($pengguna(['ap.posting.view'])); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $status = $e->getStatusCode(); }
    $ok('izin baca kontrak SAJA ditolak menautkan (403)', $status === 403,
        'menautkan kontrak menambah biaya ke HPP — itu wewenang pemegang Komponen COGS, '
        .'bukan siapa pun yang boleh membaca daftar kontrak; hasil: '.var_export($status, true));

    $lolos = false;
    try { $panggil($pengguna(['cogs.component.manage'])); $lolos = true; } catch (\Throwable) {}
    $ok('  izin Komponen COGS saja sudah cukup', $lolos && BsMukContractCogs::where('muk_id', $mukId)->exists());
    BsMukContractCogs::where('muk_id', $mukId)->delete();

    $vue = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Payable/MukKontrak/Index.vue'));
    $ok('tombol Link COGS ada di tiap baris', str_contains($vue, 'Link COGS') && str_contains($vue, 'bukaCogs(k)'));
    // Baris tabelnya sendiri membuka rincian kontrak; tanpa penahan, menekan tombol ikut pindah
    // halaman. Dicari pada ATRIBUT sel-nya — "@click.stop" juga muncul di komentar penjelas di
    // atasnya, sehingga pencarian kata polos tetap ketemu walau atributnya sudah dicopot.
    $ok('  klik tombol tidak ikut membuka rincian kontrak',
        (bool) preg_match('/<td[^>]*\s@click\.stop[\s>]/', $vue));
    $ok('  tombol dimatikan bila tidak berizin', str_contains($vue, ':disabled="! bisaLink"'));

    $show = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Cogs/Komponen/Show.vue'));
    $ok('layar komponen punya blok kontrak MUK', str_contains($show, 'Kontrak SPK (MUK) —'));
    $ok('  memuat kolom Kode Unit & Porsi', str_contains($show, '>Kode Unit<') && str_contains($show, '>Porsi<'));

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
