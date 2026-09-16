<?php

/**
 * KPR → Set Up → Kategori Reject Bank — sisi server.
 *
 * Master ini sederhana, dan justru itu bahayanya: yang bisa salah di sini TIDAK memunculkan
 * galat apa pun, hanya angka/daftar yang keliru atau pintu yang terbuka.
 *
 *  1. KEUNIKAN NAMA MELEWATI TenantScope. Rule::unique berjalan di luar global scope, jadi
 *     tanpa ->where('tenant_id', ...) keunikan menjadi LINTAS TENANT: tenant kedua tidak bisa
 *     memakai 'RPC KURANG' dan pesannya berbohong ("sudah ada") padahal miliknya belum ada.
 *     Sebaliknya tanpa ->whereNull('deleted_at'), nama yang pernah dihapus terblokir selamanya.
 *  2. tenant_id DIISI SERVER, bukan dari request. Menerimanya dari request membuka pemalsuan
 *     tenant — dan itu tidak akan pernah terlihat selama tenantnya baru satu.
 *  3. GERBANG IZIN. index memakai bankloan.setup.view; store/update/destroy memakai
 *     bankloan.setup.manage. Menyamakan keduanya memberi hak ubah kepada yang hanya boleh lihat.
 *  4. NAMA HALAMAN Inertia harus benar-benar ADA berkas .vue-nya. Salah satu huruf saja dan
 *     layarnya kosong — servernya sendiri tidak melaporkan apa pun.
 *
 * CATATAN tenancy: di tinker tenant_id() mengembalikan NULL, sehingga Rule::unique akan
 * mencari baris ber-tenant_id NULL — tidak pernah ketemu — dan uji keunikan LOLOS PALSU.
 * Karena itu 'tenant.id' diikat dulu, meniru middleware SetTenant pada request sungguhan.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-kategori-reject-bank.php';"
 */

use App\Domain\BankLoan\Models\BankRejectCategory;
use App\Http\Controllers\Web\BankLoan\Setup\BankRejectCategoryController;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

/** Request Inertia yang membawa user tertentu. */
$req = function ($user, array $data = [], string $metode = 'GET') {
    $r = \Illuminate\Http\Request::create('/kpr/setup/reject-bank', $metode, $data);
    $r->setUserResolver(fn () => $user);
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());

    return $r;
};

$user = \App\Models\User::all()->first(fn ($u) => $u->can('bankloan.setup.manage'));
if (! $user) { echo "Tidak ada user ber-izin bankloan.setup.manage — uji dihentikan.\n"; return; }
auth()->setUser($user);

// Meniru middleware SetTenant. Tanpa ini tenant_id() null dan seluruh uji keunikan tak bermakna.
app()->instance('tenant.id', (int) $user->tenant_id);

DB::beginTransaction();

try {
    $ctl = new BankRejectCategoryController;
    $tenantId = tenant_id();
    $ok('tenant aktif terikat (meniru SetTenant)', $tenantId > 0, var_export($tenantId, true));

    echo "\n=== 1. Isi awal yang diminta ada persis, urut ===\n";

    $DIMINTA = [
        'RPC KURANG',
        'DATA DIRAGUKAN',
        'TIDAK DAPAT UNIT',
        'TIDAK BANKABLE (MAYORITAS PENGHASILAN TUNAI)',
        'BATAL SEPIHAK',
    ];

    $awal = DB::table('kpr_bank_reject_categories')
        ->where('tenant_id', $tenantId)->whereNull('deleted_at')
        ->orderBy('sort_order')->orderBy('id')->pluck('name')->all();

    $ok('kelima kategori awal terbentuk', count(array_intersect($DIMINTA, $awal)) === 5,
        json_encode($awal, JSON_UNESCAPED_UNICODE));
    $ok('urutannya sama dengan yang diminta',
        array_values(array_filter($awal, fn ($n) => in_array($n, $DIMINTA, true))) === $DIMINTA,
        json_encode($awal, JSON_UNESCAPED_UNICODE));
    $ok('semuanya aktif',
        DB::table('kpr_bank_reject_categories')->where('tenant_id', $tenantId)
            ->whereIn('name', $DIMINTA)->where('is_active', 0)->count() === 0);

    echo "\n=== 2. Halaman & prop yang dikirim ke layar ===\n";

    $r = $req($user);
    $res = $ctl->index($r)->toResponse($r);
    $isi = json_decode((string) $res->getContent(), true);
    $komponen = $isi['component'] ?? '';
    $props = $isi['props'] ?? [];

    $ok('nama halaman = BankLoan/Setup/RejectBank/Index', $komponen === 'BankLoan/Setup/RejectBank/Index', $komponen);
    // Nama halaman yang salah ketik TIDAK melaporkan apa pun dari sisi server.
    $berkasVue = base_path('resources/js/Pages/'.$komponen.'.vue');
    $ok('berkas .vue halaman itu benar-benar ada', is_file($berkasVue), $berkasVue);
    $ok('prop categories berbentuk paginator', is_array($props['categories']['data'] ?? null),
        'layar menulis v-for="c in categories.data" — array datar membuat tabelnya kosong tanpa galat');
    $ok('prop filters dikirim', array_key_exists('filters', $props));
    $ok('kelima kategori awal ikut terkirim',
        count(array_intersect($DIMINTA, array_column($props['categories']['data'], 'name'))) === 5);

    echo "\n=== 3. Pencarian ===\n";

    $r2 = $req($user, ['search' => 'BANKABLE']);
    $props2 = json_decode((string) $ctl->index($r2)->toResponse($r2)->getContent(), true)['props'] ?? [];
    $nama2 = array_column($props2['categories']['data'] ?? [], 'name');
    $ok('pencarian menyaring', count($nama2) === 1 && $nama2[0] === 'TIDAK BANKABLE (MAYORITAS PENGHASILAN TUNAI)',
        json_encode($nama2, JSON_UNESCAPED_UNICODE));
    $ok('kata pencarian dikembalikan ke layar', ($props2['filters']['search'] ?? null) === 'BANKABLE');

    echo "\n=== 4. Tambah, ubah, hapus ===\n";

    $ctl->store($req($user, ['name' => 'UJI TAMBAH', 'sort_order' => 9], 'POST'));
    $baru = BankRejectCategory::where('name', 'UJI TAMBAH')->first();
    $ok('kategori baru tersimpan', $baru !== null);
    $ok('tenant_id diisi server', (int) ($baru->tenant_id ?? 0) === $tenantId, (string) ($baru->tenant_id ?? ''));
    $ok('urutan tersimpan', (int) ($baru->sort_order ?? 0) === 9);
    $ok('default aktif', (bool) ($baru->is_active ?? false) === true);

    // tenant_id dari request TIDAK boleh dipercaya.
    $ctl->store($req($user, ['name' => 'UJI PALSU', 'tenant_id' => 999999], 'POST'));
    $palsu = BankRejectCategory::withoutGlobalScopes()->where('name', 'UJI PALSU')->first();
    $ok('tenant_id dari request DIABAIKAN', (int) ($palsu->tenant_id ?? 0) === $tenantId,
        'pemalsuan tenant lewat form: dapat '.var_export($palsu->tenant_id ?? null, true));

    $ctl->update($req($user, ['name' => 'UJI UBAH', 'sort_order' => 3, 'is_active' => false], 'PUT'), $baru);
    $baru->refresh();
    $ok('nama terubah', $baru->name === 'UJI UBAH', $baru->name);
    $ok('status nonaktif tersimpan', $baru->is_active === false);

    // Menyimpan ulang TANPA mengganti nama harus boleh. Tanpa ->ignore(), barisnya bentrok
    // dengan dirinya sendiri: mengubah urutan/status saja jadi mustahil, dan pesannya
    // menuduh ada kategori kembar yang sebenarnya tidak ada.
    $ubahDiri = true;
    try {
        $ctl->update($req($user, ['name' => 'UJI UBAH', 'sort_order' => 4], 'PUT'), $baru);
    } catch (ValidationException $e) {
        $ubahDiri = false;
    }
    $ok('menyimpan ulang tanpa ganti nama TETAP boleh', $ubahDiri, 'barisnya bentrok dengan dirinya sendiri');

    // Urutan daftar mengikuti kolom Urutan, bukan urutan pembuatan. Baris ini dibuat PALING
    // AKHIR (id terbesar) tetapi ber-urutan 0, jadi ia harus muncul PALING ATAS.
    $ctl->store($req($user, ['name' => 'UJI PALING ATAS', 'sort_order' => 0], 'POST'));
    $rUrut = $req($user);
    $propsUrut = json_decode((string) $ctl->index($rUrut)->toResponse($rUrut)->getContent(), true)['props'] ?? [];
    $ok('daftar diurutkan menurut kolom Urutan, bukan id',
        (($propsUrut['categories']['data'][0]['name'] ?? null)) === 'UJI PALING ATAS',
        'teratas: '.json_encode($propsUrut['categories']['data'][0]['name'] ?? null, JSON_UNESCAPED_UNICODE));

    $idHapus = $baru->id;
    $ctl->destroy($req($user, [], 'DELETE'), $baru);
    $ok('hilang dari daftar biasa', BankRejectCategory::find($idHapus) === null);
    // SoftDeletes: kalau baris benar-benar terhapus keras, riwayat pemakaiannya ikut hilang.
    $ok('dihapus LUNAK, bukan keras',
        BankRejectCategory::withTrashed()->whereKey($idHapus)->first()?->deleted_at !== null,
        'baris hilang permanen dari basis data');

    echo "\n=== 5. Nama kembar ===\n";

    $kembar = false;
    try {
        $ctl->store($req($user, ['name' => 'RPC KURANG'], 'POST'));
    } catch (ValidationException $e) {
        $kembar = true;
        $pesan = $e->errors()['name'][0] ?? '';
    }
    $ok('nama kembar DITOLAK', $kembar, 'dua kategori bernama sama bisa masuk');
    $ok('pesannya menyebut sebabnya', $kembar && str_contains($pesan ?? '', 'sudah ada'), $pesan ?? '');

    // Nama yang sudah dihapus harus bisa dipakai lagi — kalau tidak, satu salah ketik
    // membuat nama itu terkunci selamanya tanpa cara membebaskannya dari layar.
    $bisaUlang = true;
    try {
        $ctl->store($req($user, ['name' => 'UJI UBAH'], 'POST'));   // nama milik baris yang tadi dihapus
    } catch (ValidationException $e) {
        $bisaUlang = false;
    }
    $ok('nama bekas baris terhapus BISA dipakai lagi', $bisaUlang,
        'baris soft-deleted ikut memblokir nama');

    echo "\n=== 6. Keunikan hanya di dalam tenant sendiri ===\n";

    $tenantLain = (int) DB::table('tenants')->insertGetId([
        'code' => 'UJIX', 'name' => 'UJI Tenant Lain', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('kpr_bank_reject_categories')->insert([
        'tenant_id' => $tenantLain, 'name' => 'UJI LINTAS TENANT', 'sort_order' => 1, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $lintas = true;
    try {
        $ctl->store($req($user, ['name' => 'UJI LINTAS TENANT'], 'POST'));
    } catch (ValidationException $e) {
        $lintas = false;
    }
    $ok('nama milik tenant LAIN tidak memblokir', $lintas,
        'keunikan berlaku global — tenant kedua tidak bisa memakai nama yang sama');

    $ctlLain = new BankRejectCategoryController;
    $rLihat = $req($user);
    $propsLihat = json_decode((string) $ctlLain->index($rLihat)->toResponse($rLihat)->getContent(), true)['props'] ?? [];
    $namaLihat = array_column($propsLihat['categories']['data'] ?? [], 'name');
    $ok('baris tenant lain TIDAK ikut tampil', ! in_array('UJI LINTAS TENANT', array_slice($namaLihat, 0, count($namaLihat)), true)
        || DB::table('kpr_bank_reject_categories')->where('name', 'UJI LINTAS TENANT')->where('tenant_id', $tenantId)->exists(),
        json_encode($namaLihat, JSON_UNESCAPED_UNICODE));

    echo "\n=== 7. Gerbang izin ===\n";

    $polos = new \App\Models\User([
        'tenant_id' => $tenantId, 'name' => 'UJI Tanpa Izin',
        'email' => 'uji-tanpa-izin-'.random_int(100000, 999999).'@contoh.test',
        'password' => bcrypt('rahasia'),
    ]);
    $polos->save();
    $ok('user tanpa izin terbentuk', ! $polos->can('bankloan.setup.view') && ! $polos->can('bankloan.setup.manage'));

    $kode = 0;
    try { $ctl->index($req($polos)); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('index ditolak tanpa bankloan.setup.view', $kode === 403, (string) $kode);

    $kode = 0;
    try { $ctl->store($req($polos, ['name' => 'UJI TOLAK'], 'POST')); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('store ditolak tanpa bankloan.setup.manage', $kode === 403, (string) $kode);

    $korban = BankRejectCategory::where('name', 'RPC KURANG')->first();
    $kode = 0;
    try { $ctl->update($req($polos, ['name' => 'UJI TOLAK'], 'PUT'), $korban); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('update ditolak tanpa izin kelola', $kode === 403, (string) $kode);

    $kode = 0;
    try { $ctl->destroy($req($polos, [], 'DELETE'), $korban); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
    $ok('destroy ditolak tanpa izin kelola', $kode === 403, (string) $kode);
    $ok('kategorinya memang tidak jadi terhapus', BankRejectCategory::where('name', 'RPC KURANG')->exists());

    /* MELIHAT ≠ MENGUBAH. Tanpa user yang punya view TAPI TIDAK punya manage, menyamakan
       kedua izin di controller tidak akan ketahuan: user tanpa izin apa pun tetap ditolak,
       dan user super tetap diterima. Justru pemegang hak baca-saja inilah yang bisa
       diam-diam mendapat hak ubah. */
    $pembaca = new \App\Models\User([
        'tenant_id' => $tenantId, 'name' => 'UJI Hanya Lihat',
        'email' => 'uji-hanya-lihat-'.random_int(100000, 999999).'@contoh.test',
        'password' => bcrypt('rahasia'),
    ]);
    $pembaca->save();
    $pembaca->givePermissionTo('bankloan.setup.view');
    $pembaca = $pembaca->fresh();
    $ok('user hanya-lihat terbentuk', $pembaca->can('bankloan.setup.view') && ! $pembaca->can('bankloan.setup.manage'),
        'view='.var_export($pembaca->can('bankloan.setup.view'), true).' manage='.var_export($pembaca->can('bankloan.setup.manage'), true));

    $bolehLihat = true;
    try { $ctl->index($req($pembaca)); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $bolehLihat = false; }
    $ok('hanya-lihat BOLEH membuka daftarnya', $bolehLihat, 'gerbang index terlalu ketat');

    foreach ([
        'store'   => fn () => $ctl->store($req($pembaca, ['name' => 'UJI DARI PEMBACA'], 'POST')),
        'update'  => fn () => $ctl->update($req($pembaca, ['name' => 'UJI DARI PEMBACA'], 'PUT'), $korban),
        'destroy' => fn () => $ctl->destroy($req($pembaca, [], 'DELETE'), $korban),
    ] as $aksi => $jalan) {
        $kode = 0;
        try { $jalan(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
        $ok("hanya-lihat DITOLAK pada $aksi", $kode === 403, (string) $kode);
    }
    $ok('tidak ada kategori yang terbuat oleh pembaca', ! BankRejectCategory::where('name', 'UJI DARI PEMBACA')->exists());

    echo "\n=== 8. Rute terdaftar & mengarah ke controller ini ===\n";

    foreach ([
        'kpr.setup.reject-bank.index'   => 'index',
        'kpr.setup.reject-bank.store'   => 'store',
        'kpr.setup.reject-bank.update'  => 'update',
        'kpr.setup.reject-bank.destroy' => 'destroy',
    ] as $nama => $aksi) {
        $rute = \Illuminate\Support\Facades\Route::getRoutes()->getByName($nama);
        $ok("rute $nama ada", $rute !== null);
        if ($rute) {
            $ok("  → $aksi pada BankRejectCategoryController",
                str_contains((string) $rute->getActionName(), 'BankRejectCategoryController@'.$aksi),
                (string) $rute->getActionName());
        }
    }
    // Route-model-binding mencocokkan lewat NAMA parameter, bukan urutan: {rejectCategory}
    // dengan argumen $category membuat 404 yang tidak menyebut sebabnya.
    $ruteUbah = \Illuminate\Support\Facades\Route::getRoutes()->getByName('kpr.setup.reject-bank.update');
    $ok('parameter rutenya bernama {category}', $ruteUbah && $ruteUbah->parameterNames() === ['category'],
        json_encode($ruteUbah?->parameterNames()));

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
} catch (\Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
}
