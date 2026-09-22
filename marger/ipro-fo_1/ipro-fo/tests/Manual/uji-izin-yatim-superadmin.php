<?php

/**
 * Izin yatim → menu yang hilang dari layar, dan perbaikannya yang tidak boleh merusak.
 *
 * MASALAHNYA, dalam satu kalimat: GranularPermissionSeeder hanya MEMBUAT izin dan tidak
 * memberikannya kepada peran mana pun, sedangkan yang memberi SuperAdmin seluruh izin adalah
 * RoleSeeder yang tidak ikut dijalankan setiap kali fitur baru menambah izin. Izin yang lahir
 * belakangan karena itu menjadi YATIM — ada di tabel permissions, tidak dipegang satu peran pun —
 * dan sidebar (yang menyaring lewat daftar nama izin, bukan lewat Gate) menyembunyikan menunya
 * dari SEMUA orang. Halamannya sendiri tetap bisa dibuka dengan mengetik alamatnya, jadi tidak
 * ada satu pun galat yang muncul. Persis itu yang menimpa sub-modul "Komponen COGS".
 *
 * Yang dijaga, berurut dari yang paling mahal bila salah:
 *
 *  1. PERBAIKANNYA TIDAK MENYENTUH PERAN LAIN. Jalan pintas yang tampak benar —
 *     `db:seed --class=RoleSeeder` — memakai syncPermissions dengan daftar yang DIPATOK di kode
 *     untuk seluruh peran non-SuperAdmin, sehingga setiap penyesuaian hak akses yang pernah
 *     dilakukan lewat Settings → Roles lenyap tanpa peringatan. Uji ini mencabut izin dari
 *     sebuah peran biasa lalu membuktikan perbaikan TIDAK mengembalikannya.
 *  2. PERBAIKANNYA MEMULIHKAN SUPERADMIN, dan hanya menambah — tidak pernah mencabut.
 *  3. AMAN DIULANG: dijalankan dua kali tidak menggandakan baris.
 *  4. down() TIDAK MENCABUT APA PUN — ia tak bisa membedakan izin yang tadinya memang belum
 *     dipegang dari izin yang diberikan operator sendiri sesudahnya.
 *  5. SUB-MODUL "Komponen COGS" benar-benar terpasang di ketiga pintu masuknya (sidebar, hub
 *     modul, dan halaman sub-modul), dengan keempat tautan yang diminta.
 *
 * Seluruh tulisan dibungkus transaksi lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-izin-yatim-superadmin.php';"
 */

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

$lulus = 0;
$gagal = 0;
$tuntas = false;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$migrasi = fn () => require base_path('database/migrations/2026_09_23_100000_beri_izin_yatim_ke_superadmin.php');
$yatim = fn () => DB::table('permissions as p')
    ->leftJoin('role_has_permissions as rp', 'rp.permission_id', '=', 'p.id')
    ->whereNull('rp.role_id')->count();
$jumlahIzin = fn (string $peran) => DB::table('role_has_permissions as rp')
    ->join('roles as r', 'r.id', '=', 'rp.role_id')->where('r.name', $peran)->count();

echo "\n=== 0. Keadaan sekarang ===\n";

$totalIzin = DB::table('permissions')->count();
$ok('tidak ada izin yatim tersisa', $yatim() === 0,
    'ada izin yang tidak dipegang peran mana pun — menunya tersembunyi dari semua orang');
$ok('SuperAdmin memegang SELURUH izin', $jumlahIzin('SuperAdmin') === $totalIzin,
    'SuperAdmin memegang '.$jumlahIzin('SuperAdmin').' dari '.$totalIzin);
$ok('izin Komponen COGS ada & dipegang SuperAdmin',
    DB::table('role_has_permissions as rp')
        ->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
        ->where('r.name', 'SuperAdmin')
        ->whereIn('p.name', ['cogs.component.view', 'cogs.component.manage'])->count() === 2);

DB::beginTransaction();

try {
    echo "\n=== 1. Perbaikan memulihkan SuperAdmin ===\n";

    $superId = (int) DB::table('roles')->where('name', 'SuperAdmin')->value('id');
    $izinUji = (int) DB::table('permissions')->where('name', 'cogs.component.view')->value('id');
    DB::table('role_has_permissions')->where('role_id', $superId)->where('permission_id', $izinUji)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $ok('prasyarat: izinnya berhasil dicabut dulu', $yatim() === 1);

    $migrasi()->up();
    $ok('SuperAdmin mendapatkannya kembali', $yatim() === 0);
    $ok('  jumlah izin SuperAdmin kembali utuh', $jumlahIzin('SuperAdmin') === $totalIzin);

    echo "\n=== 2. Peran lain TIDAK disentuh ===\n";

    // Inilah pembeda antara perbaikan ini dan menjalankan ulang RoleSeeder. Peran biasa yang
    // izinnya sengaja dikurangi operator harus TETAP berkurang sesudah perbaikan dijalankan.
    $peranBiasa = DB::table('roles')->where('name', 'FinanceManager')->value('id');
    $sebelum = $jumlahIzin('FinanceManager');
    $dicabut = (int) DB::table('role_has_permissions')->where('role_id', $peranBiasa)->value('permission_id');
    DB::table('role_has_permissions')->where('role_id', $peranBiasa)->where('permission_id', $dicabut)->delete();

    $migrasi()->up();

    $ok('izin peran biasa yang dicabut TIDAK dikembalikan', $jumlahIzin('FinanceManager') === $sebelum - 1,
        'perbaikan menimpa hak akses peran lain — penyesuaian operator lenyap tanpa peringatan');
    $ok('  peran biasa tidak ikut kebagian izin SuperAdmin', $jumlahIzin('FinanceManager') < $totalIzin);
    $ok('  izin yatimnya memang muncul (peran biasa tidak menutupinya)', $yatim() === 0,
        'izin itu masih dipegang SuperAdmin, jadi ia bukan yatim — ini menegaskan cara hitungnya benar');

    echo "\n=== 3. Aman diulang ===\n";

    $baris = DB::table('role_has_permissions')->count();
    $migrasi()->up();
    $migrasi()->up();
    $ok('dijalankan dua kali lagi tidak menambah baris', DB::table('role_has_permissions')->count() === $baris,
        'baris ganda pada role_has_permissions — indeks uniknya akan menolak, atau izin tercatat berkali-kali');

    echo "\n=== 4. down() tidak mencabut apa pun ===\n";

    $sebelumTurun = DB::table('role_has_permissions')->count();
    $migrasi()->down();
    $ok('down() membiarkan seluruh pemberian izin', DB::table('role_has_permissions')->count() === $sebelumTurun,
        'membatalkan migrasi ini mencabut hak akses — termasuk yang diberikan operator sendiri sesudahnya');

    echo "\n=== 5. Seeder tidak lagi melahirkan izin yatim ===\n";

    $src = file_get_contents(base_path('database/seeders/GranularPermissionSeeder.php'));
    $ok('GranularPermissionSeeder melengkapi SuperAdmin', str_contains($src, 'lengkapiSuperAdmin()'));
    // Perilakunya, bukan hanya teksnya: seeder yang memanggil fungsi kosong tetap lolos
    // pemeriksaan teks di atas.
    DB::table('role_has_permissions')->where('role_id', $superId)->where('permission_id', $izinUji)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $ok('  prasyarat: izin dicabut lagi', $yatim() === 1);
    (new \Database\Seeders\GranularPermissionSeeder)->run();
    $ok('  seeder mengembalikannya', $yatim() === 0,
        'menjalankan seeder izin tetap meninggalkan izin yang tak dipegang siapa pun');
    $ok('  seeder tidak menyentuh peran biasa', $jumlahIzin('FinanceManager') === $sebelum - 1,
        'seeder izin ikut mengubah hak akses peran lain');

    echo "\n=== 6. Sub-modul Komponen COGS terpasang di ketiga pintu masuknya ===\n";

    $nav = file_get_contents(base_path('resources/js/Components/Layout/navGroups.ts'));
    $posKomponen = strpos($nav, "label: 'Komponen COGS'");
    $posHpp = strpos($nav, "label: 'Transaksi HPP RealCost'");
    $posSaldo = strpos($nav, "label: 'Saldo Awal Realisasi', icon");
    $ok('sidebar memuat grup "Komponen COGS"', $posKomponen !== false);
    $ok('  letaknya DI BAWAH "Transaksi HPP RealCost"',
        $posHpp !== false && $posKomponen !== false && $posHpp < $posKomponen);
    $ok('  dan di atas "Saldo Awal Realisasi"',
        $posSaldo !== false && $posKomponen !== false && $posKomponen < $posSaldo);

    $hub = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Cogs/Hub.vue'));
    $sec = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Cogs/Section.vue'));
    $ok('hub modul COGS memuatnya', str_contains($hub, "group: 'Komponen COGS'"),
        'pengguna yang masuk lewat hub tidak akan pernah melihat sub-modul ini');
    $ok('halaman sub-modul /submodule/cogs/komponen tidak lagi kosong',
        str_contains($sec, "'komponen': 'Komponen COGS'") && str_contains($sec, "'komponen': ["),
        'alamatnya dikenali rute tetapi memulangkan halaman kosong berjudul "Sub-Modul"');

    foreach (['tanah', 'housing', 'infrastruktur', 'license-permit'] as $slug) {
        $ok('  sidebar menaut komponen '.$slug, str_contains($nav, "komponen: '".$slug."'"));
    }

    echo "\n=== 7. Keempat tautan menunjuk modul sumber yang diminta ===\n";

    $K = \App\Http\Controllers\Web\BusinessSupport\Cogs\CogsComponentController::KOMPONEN;
    $ok('Tanah → Kategori Invoice (Account Payable)', ($K['tanah']['sumber'] ?? null) === 'ap');
    $ok('License Permit → Kategori Invoice (Account Payable)', ($K['license-permit']['sumber'] ?? null) === 'ap');
    $ok('Housing → Surat Perintah Kerja (Contractor Management)',
        ($K['housing']['sumber'] ?? null) === 'spk' && ($K['housing']['jenis'] ?? null) === 'bangunan');
    $ok('Infrastruktur → Surat Perintah Kerja (Contractor Management)',
        ($K['infrastruktur']['sumber'] ?? null) === 'spk' && ($K['infrastruktur']['jenis'] ?? null) === 'sarana_prasarana');

    $tuntas = true;
} catch (\Throwable $e) {
    $gagal++;
    echo "\n  GAGAL (galat tak tertangani) — ".get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    // Tanpa penanda "sampai ujung", galat di tengah jalan meninggalkan GAGAL 0 dan skrip ini
    // akan mencetak SEMUA UJI LULUS padahal separuh pemeriksaannya tak pernah berjalan.
    echo ($gagal === 0 && $tuntas) ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
    echo "(data uji dibatalkan)\n";
}
