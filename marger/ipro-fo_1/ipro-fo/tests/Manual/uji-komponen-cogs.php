<?php

/**
 * COGS → Komponen COGS (Tanah · Housing · Infrastruktur · License Permit) — sisi server.
 *
 * Seluruh kegagalan di sini berupa ANGKA BIAYA YANG SALAH tanpa satu pun galat:
 *
 *  1. TOTAL DARI HALAMAN, BUKAN DARI SELURUH DATA. Tabelnya dipaginasi 100 baris. Kalau
 *     ringkasan dijumlah dari baris yang sedang tampil, total komponen Tanah diam-diam
 *     berubah setiap kali pindah halaman. Uji membuat 101 invoice untuk membuktikannya.
 *  2. BIAYA YANG DIBATALKAN IKUT TERHITUNG. Invoice void/cancelled dan SPK cancelled
 *     bukan biaya.
 *  3. SATU KATEGORI DI DUA KOMPONEN. Invoice yang sama akan terhitung sebagai tanah DAN
 *     perizinan — HPP-nya menggelembung sebesar invoice itu.
 *  4. SCOPE TENANT GAGAL DIAM-DIAM. TenantScope & SoftDeletes menulis nama tabel lengkap
 *     (qualifyColumn). Kueri yang memberi alias pada tabel utama jalan mulus di tinker
 *     (tenant_id() null → scope tidak aktif) dan baru pecah pada request sungguhan. Karena
 *     itu 'tenant.id' DIIKAT di sini, dan invoice milik tenant lain sengaja disisipkan.
 *  5. NILAI SPK TANPA ADDENDUM. Kontrak saja membuat SPK yang sudah di-addendum tampak
 *     lebih murah daripada yang sebenarnya dibayar.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-komponen-cogs.php';"
 */

use App\Domain\BusinessSupport\Models\BsCogsCategory;
use App\Domain\BusinessSupport\Models\BsCogsComponentApCategory;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsComponentController as K;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = \App\Models\User::all()->first(fn ($u) => $u->hasRole('SuperAdmin')) ?? \App\Models\User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
// Meniru middleware SetTenant — tanpa ini TenantScope TIDAK aktif dan jebakan nomor 4 tak teruji.
app()->instance('tenant.id', (int) $user->tenant_id);

$req = function ($u, array $data = [], string $metode = 'GET', string $url = '/business-support/cogs-komponen/tanah') {
    $r = \Illuminate\Http\Request::create($url, $metode, $data);
    $r->setUserResolver(fn () => $u);
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());

    return $r;
};
// Dirakit lewat container, bukan `new K`: controller ini kini menerima BukuBesarDokumen
// lewat konstruktor (sumber baris buku besar untuk komponen). Merakitnya sendiri berarti uji
// ini ikut pecah setiap kali ketergantungannya bertambah.
$ctl = app(K::class);
$lihat = function (string $komponen, array $saring = [], $u = null) use ($ctl, $req, $user) {
    $r = $req($u ?? $user, $saring, 'GET', '/business-support/cogs-komponen/'.$komponen);

    return json_decode((string) $ctl->show($r, $komponen)->toResponse($r)->getContent(), true);
};
$tautkan = function (string $komponen, array $ids, $u = null) use ($ctl, $req, $user) {
    return $ctl->simpanTautan($req($u ?? $user, ['kategori' => $ids], 'PUT', '/business-support/cogs-komponen/'.$komponen.'/kategori-invoice'), $komponen);
};

DB::beginTransaction();

try {
    $tenantId = tenant_id();
    $now = now();
    $akun = (int) DB::table('bs_accounts')->value('id');
    $supp = (int) DB::table('bs_suppliers')->insertGetId(['tenant_id' => $tenantId, 'code' => 'UJI-SUP-KC', 'name' => 'UJI Supplier Komponen', 'created_at' => $now, 'updated_at' => $now]);

    $kat = function (string $code, string $name, ?int $tenant = null) use ($tenantId, $akun, $now) {
        return (int) DB::table('bs_ap_categories')->insertGetId([
            'tenant_id' => $tenant ?? $tenantId, 'code' => $code, 'name' => $name,
            'debit_account_id' => $akun, 'payable_account_id' => $akun, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $inv = function (int $katId, float $total, array $lain = []) use ($tenantId, $supp, $akun, $now) {
        static $n = 0;
        $n++;

        return (int) DB::table('bs_ap_invoices')->insertGetId(array_merge([
            'tenant_id' => $tenantId, 'invoice_number' => 'UJI-KC-'.$n.'-'.random_int(1000, 9999),
            'invoice_date' => '2026-03-15', 'supplier_id' => $supp, 'expense_account_id' => $akun,
            'payable_account_id' => $akun, 'description' => 'uji komponen '.$n, 'category_id' => $katId,
            'total' => $total, 'paid_amount' => 0, 'status' => 'open',
            'created_at' => $now, 'updated_at' => $now,
        ], $lain));
    };

    echo "\n=== 1. Definisi komponen menyatu dengan yang sudah ada ===\n";

    $ok('empat komponen', array_keys(K::KOMPONEN) === ['tanah', 'housing', 'infrastruktur', 'license-permit'],
        json_encode(array_keys(K::KOMPONEN)));
    foreach (K::KOMPONEN as $slug => $d) {
        $ok("$slug memakai kunci HPP yang SUDAH ADA ({$d['cogs']})", array_key_exists($d['cogs'], BsCogsCategory::COMPONENTS),
            'konsep komponen kedua yang tidak dikenal CogsService');
    }
    $ok('Housing = SPK jenis yang dilabeli "Housing"', (BsWorkOrder::JENIS[K::KOMPONEN['housing']['jenis']] ?? null) === 'Housing');
    $ok('Infrastruktur = SPK jenis yang dilabeli "Infrastruktur"', (BsWorkOrder::JENIS[K::KOMPONEN['infrastruktur']['jenis']] ?? null) === 'Infrastruktur');
    $ok('Tanah & License Permit bersumber AP', K::KOMPONEN['tanah']['sumber'] === 'ap' && K::KOMPONEN['license-permit']['sumber'] === 'ap');

    echo "\n=== 2. Tautan Kategori Invoice → Tanah ===\n";

    $katTanah = $kat('UJI-TANAH-KC', 'UJI TANAH CLUSTER');
    $katIzin = $kat('UJI-PERMIT-KC', 'UJI LICENSE PERMIT');
    $katLain = $kat('UJI-ATK-KC', 'UJI ALAT TULIS');

    $i1 = $inv($katTanah, 100000000, ['paid_amount' => 40000000, 'status' => 'partial']);
    $i2 = $inv($katTanah, 50000000);
    $inv($katTanah, 777000000, ['status' => 'void']);          // dibatalkan — TIDAK dihitung
    $inv($katTanah, 888000000, ['status' => 'cancelled']);     // dibatalkan — TIDAK dihitung
    $inv($katTanah, 999000000, ['deleted_at' => $now]);        // terhapus  — TIDAK dihitung
    $inv($katIzin, 30000000);
    $inv($katLain, 5000000);

    // Invoice TENANT LAIN pada kategori yang sama — hanya tersaring bila TenantScope benar-benar
    // bekerja pada kueri ini.
    $tenantLain = (int) DB::table('tenants')->insertGetId(['code' => 'UJIKC', 'name' => 'UJI Tenant KC', 'created_at' => $now, 'updated_at' => $now]);
    $inv($katTanah, 555000000, ['tenant_id' => $tenantLain]);

    $awal = $lihat('tanah');
    $ok('sebelum ditautkan: Tanah kosong', ($awal['props']['ringkas']['jumlah'] ?? -1) === 0, json_encode($awal['props']['ringkas'] ?? null));
    $kTanah = collect($awal['props']['kategori'] ?? [])->firstWhere('id', $katTanah);
    $ok('kategori UJI-TANAH DISARANKAN untuk Tanah', ($kTanah['disarankan'] ?? false) === true);
    $ok('  tetapi TIDAK ditautkan otomatis', ($kTanah['tertaut'] ?? true) === false,
        'biaya dipindahkan ke komponen berdasarkan tebakan nama');
    $ok('kategori ATK tidak disarankan', (collect($awal['props']['kategori'])->firstWhere('id', $katLain)['disarankan'] ?? true) === false);

    $tautkan('tanah', [$katTanah]);
    $p = $lihat('tanah')['props'];
    $ok('sesudah ditautkan: 2 invoice', ($p['ringkas']['jumlah'] ?? 0) === 2, json_encode($p['ringkas']));
    $ok('total = 150 jt (void, cancelled, terhapus, tenant lain TIDAK ikut)', abs(($p['ringkas']['nilai'] ?? 0) - 150000000) < 0.01,
        'dapat '.($p['ringkas']['nilai'] ?? 'null'));
    $ok('terbayar = 40 jt', abs(($p['ringkas']['terbayar'] ?? 0) - 40000000) < 0.01, (string) ($p['ringkas']['terbayar'] ?? ''));
    $ok('sisa hutang = 110 jt', abs(($p['ringkas']['sisa'] ?? 0) - 110000000) < 0.01, (string) ($p['ringkas']['sisa'] ?? ''));
    $nomorTampil = array_column($p['baris']['data'] ?? [], 'id');
    $ok('kedua invoice itulah yang tampil', count(array_intersect([$i1, $i2], $nomorTampil)) === 2 && count($nomorTampil) === 2,
        json_encode($nomorTampil));
    $ok('invoice kategori lain tidak ikut', ! collect($p['baris']['data'])->contains(fn ($r) => str_contains((string) $r['kategori'], 'UJI-ATK')));

    $izin = $lihat('license-permit')['props'];
    $ok('License Permit tetap kosong (belum ditautkan)', ($izin['ringkas']['jumlah'] ?? -1) === 0);
    $ok('  dan kategori UJI-TANAH tercatat milik Tanah', (collect($izin['kategori'])->firstWhere('id', $katTanah)['milikLain'] ?? null) === 'Tanah');
    $ok('  serta tidak lagi disarankan di sana', (collect($izin['kategori'])->firstWhere('id', $katTanah)['disarankan'] ?? true) === false);

    echo "\n=== 3. Satu kategori hanya untuk SATU komponen ===\n";

    $bentrok = false;
    $pesan = '';
    try { $tautkan('license-permit', [$katIzin, $katTanah]); }
    catch (ValidationException $e) { $bentrok = true; $pesan = (string) ($e->errors()['kategori'][0] ?? ''); }
    $ok('menautkan kategori milik Tanah ke License Permit DITOLAK', $bentrok, 'invoice yang sama terhitung di dua komponen');
    $ok('  pesannya menyebut kategori & pemiliknya', str_contains($pesan, 'UJI-TANAH-KC') && str_contains($pesan, 'Tanah'), $pesan);
    $ok('  dan penolakan itu TIDAK menyimpan sebagian', BsCogsComponentApCategory::where('component', 'permit')->where('ap_category_id', $katIzin)->doesntExist(),
        'kategori lain dalam permintaan yang sama tetap tersimpan');

    $tautkan('license-permit', [$katIzin]);
    $ok('License Permit menghitung invoice-nya sendiri', abs(($lihat('license-permit')['props']['ringkas']['nilai'] ?? 0) - 30000000) < 0.01);
    $ok('Tanah tidak berubah karenanya', abs(($lihat('tanah')['props']['ringkas']['nilai'] ?? 0) - 150000000) < 0.01);

    echo "\n=== 4. Melepas & mengganti tautan ===\n";

    $tautkan('tanah', []);
    $ok('dilepas semua → Tanah kembali kosong', ($lihat('tanah')['props']['ringkas']['jumlah'] ?? -1) === 0);
    $ok('  baris tautannya benar-benar terhapus', BsCogsComponentApCategory::where('component', 'tanah')->doesntExist());
    $ok('  tautan License Permit TIDAK ikut terhapus', BsCogsComponentApCategory::where('component', 'permit')->where('ap_category_id', $katIzin)->exists(),
        'melepas satu komponen menghapus tautan komponen lain');

    $tautkan('tanah', [$katTanah]);
    $tautkan('tanah', [$katTanah]);   // disimpan dua kali
    $ok('menyimpan ulang tidak menggandakan tautan', BsCogsComponentApCategory::where('ap_category_id', $katTanah)->count() === 1);

    echo "\n=== 5. Total dihitung dari SELURUH data, bukan halaman ===\n";

    // Tabel dipaginasi 100. Dengan 101 invoice, total yang dijumlah dari halaman akan kurang
    // tepat satu invoice — cukup untuk ketahuan, dan cukup kecil untuk tidak terlihat mata.
    $katBanyak = $kat('UJI-TANAH-BANYAK', 'UJI TANAH BANYAK');
    for ($i = 0; $i < 99; $i++) {
        $inv($katBanyak, 1000000);
    }
    $tautkan('tanah', [$katTanah, $katBanyak]);   // 2 + 99 = 101 invoice
    $banyak = $lihat('tanah')['props'];
    $ok('halaman pertama memuat 100 baris', count($banyak['baris']['data'] ?? []) === 100, (string) count($banyak['baris']['data'] ?? []));
    $ok('ringkasan menghitung 101 invoice', ($banyak['ringkas']['jumlah'] ?? 0) === 101, (string) ($banyak['ringkas']['jumlah'] ?? ''));
    $ok('total = 150 jt + 99 jt = 249 jt', abs(($banyak['ringkas']['nilai'] ?? 0) - 249000000) < 0.01,
        'dapat '.($banyak['ringkas']['nilai'] ?? '').' — total dijumlah dari halaman yang tampil');

    echo "\n=== 6. Saringan tahun & pencarian ===\n";

    $inv($katTanah, 12000000, ['invoice_date' => '2025-06-01', 'description' => 'invoice tahun lalu']);
    $ok('tahun 2025 → hanya invoice 2025', ($lihat('tanah', ['tahun' => 2025])['props']['ringkas']['jumlah'] ?? 0) === 1);
    $ok('tanpa tahun → semua (102)', ($lihat('tanah')['props']['ringkas']['jumlah'] ?? 0) === 102);
    $cari = $lihat('tanah', ['search' => 'tahun lalu'])['props'];
    $ok('pencarian keterangan menyaring', ($cari['ringkas']['jumlah'] ?? 0) === 1 && abs(($cari['ringkas']['nilai'] ?? 0) - 12000000) < 0.01);
    $ok('pencarian nama kategori menyaring', ($lihat('tanah', ['search' => 'TANAH BANYAK'])['props']['ringkas']['jumlah'] ?? 0) === 99);

    echo "\n=== 7. Housing & Infrastruktur dari Surat Perintah Kerja ===\n";

    $spk = function (string $jenis, float $kontrak, array $lain = []) use ($tenantId, $supp, $akun, $user, $now) {
        static $n = 0;
        $n++;

        return (int) DB::table('bs_work_orders')->insertGetId(array_merge([
            'tenant_id' => $tenantId, 'spk_number' => 'UJI-SPK-KC-'.$n.'-'.random_int(1000, 9999), 'spk_date' => '2026-04-10',
            'supplier_id' => $supp, 'construction_account_id' => $akun, 'title' => 'UJI pekerjaan '.$jenis.' '.$n,
            'jenis' => $jenis, 'contract_value' => $kontrak, 'status' => 'active', 'created_by' => $user->id,
            'created_at' => $now, 'updated_at' => $now,
        ], $lain));
    };

    $dasarH = $lihat('housing')['props']['ringkas'];
    $dasarI = $lihat('infrastruktur')['props']['ringkas'];

    $spk('bangunan', 100000000, ['addendum_value' => 20000000, 'progress_value' => 50000000, 'cluster' => 'C1', 'unit' => 'A-01']);
    $spk('bangunan', 60000000, ['status' => 'cancelled', 'cancelled_at' => '2026-05-01']);   // TIDAK dihitung
    $spk('bangunan', 70000000, ['deleted_at' => $now]);                                     // TIDAK dihitung
    $spk('bangunan', 80000000, ['tenant_id' => $tenantLain]);                               // TIDAK dihitung
    $spk('sarana_prasarana', 200000000, ['progress_value' => 25000000]);
    $spk('lain_lain', 999000000);                                                           // bukan keduanya

    $h = $lihat('housing')['props'];
    $ok('Housing: bertambah tepat 1 SPK', ($h['ringkas']['jumlah'] - $dasarH['jumlah']) === 1, json_encode($h['ringkas']));
    $ok('Housing: nilai SPK = kontrak + ADDENDUM (120 jt)', abs(($h['ringkas']['nilai'] - $dasarH['nilai']) - 120000000) < 0.01,
        'selisih '.($h['ringkas']['nilai'] - $dasarH['nilai']).' — addendum tidak ikut atau SPK batal ikut');
    $ok('Housing: progres bertambah 50 jt', abs(($h['ringkas']['progres'] - $dasarH['progres']) - 50000000) < 0.01);
    $ok('Housing: sisa kontrak = nilai − progres', abs($h['ringkas']['sisa'] - max(0, $h['ringkas']['nilai'] - $h['ringkas']['progres'])) < 0.01);
    $baru = collect($h['baris']['data'])->firstWhere('lokasi', 'C1 · A-01');
    $ok('baris SPK membawa nilai & sisanya sendiri', $baru !== null && abs($baru['nilai'] - 120000000) < 0.01 && abs($baru['sisa'] - 70000000) < 0.01,
        json_encode($baru));
    $ok('Housing: SPK lain_lain & sarana tidak ikut', ! collect($h['baris']['data'])->contains(fn ($r) => str_contains((string) $r['judul'], 'lain_lain') || str_contains((string) $r['judul'], 'sarana_prasarana')));

    $inf = $lihat('infrastruktur')['props'];
    $ok('Infrastruktur: bertambah tepat 1 SPK (200 jt)', ($inf['ringkas']['jumlah'] - $dasarI['jumlah']) === 1
        && abs(($inf['ringkas']['nilai'] - $dasarI['nilai']) - 200000000) < 0.01, json_encode($inf['ringkas']));
    $ok('Infrastruktur memakai label jenis "Infrastruktur"', ($inf['jenisLabel'] ?? '') === 'Infrastruktur');
    $ok('halaman SPK tidak membawa daftar kategori invoice', ! array_key_exists('kategori', $inf));

    echo "\n=== 8. Gerbang izin & slug ===\n";

    $polos = new \App\Models\User(['tenant_id' => $tenantId, 'name' => 'UJI KC Tanpa Izin',
        'email' => 'uji-kc-'.random_int(100000, 999999).'@contoh.test', 'password' => bcrypt('rahasia')]);
    $polos->save();
    $kode = 0;
    try { $ctl->show($req($polos), 'tanah'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('tanpa izin: halaman ditolak', $kode === 403, (string) $kode);

    $pembaca = new \App\Models\User(['tenant_id' => $tenantId, 'name' => 'UJI KC Lihat',
        'email' => 'uji-kc-lihat-'.random_int(100000, 999999).'@contoh.test', 'password' => bcrypt('rahasia')]);
    $pembaca->save();
    $pembaca->givePermissionTo('cogs.component.view');
    $pembaca = $pembaca->fresh();
    $ok('user hanya-lihat terbentuk', $pembaca->can('cogs.component.view') && ! $pembaca->can('cogs.component.manage'));

    $boleh = true;
    try { $lihat('tanah', [], $pembaca); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $boleh = false; }
    $ok('hanya-lihat BOLEH membuka komponen', $boleh);
    $ok('  dan layarnya tahu ia tidak boleh mengatur', ($lihat('tanah', [], $pembaca)['props']['bisaAtur'] ?? true) === false);
    $kode = 0;
    try { $tautkan('tanah', [], $pembaca); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('hanya-lihat DITOLAK saat mengubah tautan', $kode === 403, (string) $kode);
    $ok('  dan tautannya memang tidak berubah', BsCogsComponentApCategory::where('component', 'tanah')->count() === 2);

    $kode = 0;
    try { $ctl->show($req($user), 'apa-saja'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('slug tak dikenal → 404', $kode === 404, (string) $kode);
    $kode = 0;
    try { $tautkan('housing', [$katTanah]); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('menautkan kategori invoice ke Housing (sumber SPK) → 404', $kode === 404, (string) $kode);

    // Kategori milik tenant lain tidak boleh ditautkan walau id-nya ditebak.
    $katAsing = $kat('UJI-ASING-KC', 'UJI TANAH ASING', $tenantLain);
    $asing = false;
    try { $tautkan('tanah', [$katTanah, $katBanyak, $katAsing]); } catch (ValidationException $e) { $asing = true; }
    $ok('kategori milik tenant lain DITOLAK', $asing, 'kategori tenant lain bisa ditautkan dengan menebak id');

    echo "\n=== 9. Rute ===\n";

    $rute = \Illuminate\Support\Facades\Route::getRoutes();
    $ok('rute tampil terdaftar', str_contains((string) $rute->getByName('bs.cogs.komponen.show')?->getActionName(), 'CogsComponentController@show'));
    $ok('rute tautan terdaftar', str_contains((string) $rute->getByName('bs.cogs.komponen.tautan')?->getActionName(), 'CogsComponentController@simpanTautan'));
    foreach (['tanah', 'housing', 'infrastruktur', 'license-permit'] as $s) {
        $cocok = true;
        try { $rute->match(\Illuminate\Http\Request::create('/business-support/cogs-komponen/'.$s, 'GET')); } catch (\Throwable $e) { $cocok = false; }
        $ok("URL komponen $s dikenali", $cocok);
    }
    $tolak = false;
    try { $rute->match(\Illuminate\Http\Request::create('/business-support/cogs-komponen/apa-saja', 'GET')); } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) { $tolak = true; }
    $ok('URL komponen asing tidak dikenali rute', $tolak);
    $ok('halaman Inertia-nya ada', is_file(base_path('resources/js/Pages/BusinessSupport/Cogs/Komponen/Show.vue')));
    $ok('izin view & manage ada', DB::table('permissions')->whereIn('name', ['cogs.component.view', 'cogs.component.manage'])->count() === 2);

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
} catch (\Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
}
