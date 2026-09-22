<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Berikan kepada peran SuperAdmin setiap izin yang belum dipegangnya.
 *
 * ── Kenapa ini perlu ──
 * GranularPermissionSeeder hanya MEMBUAT izin; ia tidak pernah memberikannya kepada peran mana
 * pun. Yang memberi SuperAdmin seluruh izin adalah RoleSeeder (syncPermissions(Permission::all())),
 * dan seeder itu tidak dijalankan lagi setiap kali sebuah fitur menambah izin baru. Pada basis
 * data yang RoleSeeder-nya terlanjur jalan lebih dulu, izin yang lahir belakangan menjadi YATIM:
 * ada di tabel permissions, tidak dipegang satu peran pun.
 *
 * Akibatnya tidak berupa galat, melainkan menu yang hilang. Sidebar menyaring lewat daftar nama
 * izin (HandleInertiaRequests: getAllPermissions()), yang dibaca dari role_has_permissions dan
 * tidak terpengaruh Gate::before. Jadi SuperAdmin BISA membuka halamannya dengan mengetik
 * alamatnya, tetapi menunya tidak pernah muncul. Itulah yang terjadi pada sub-modul
 * "Komponen COGS": 'cogs.component.view' dan 'cogs.component.manage' adalah satu-satunya dua
 * izin yatim dari 539 izin, dan seluruh sub-modulnya tak terlihat di layar.
 *
 * ── Kenapa BUKAN "jalankan ulang RoleSeeder" ──
 * RoleSeeder memakai syncPermissions dengan daftar yang DIPATOK di kode untuk SELURUH peran
 * non-SuperAdmin (dan `default => []` untuk peran yang tak disebut). Menjalankannya ulang akan
 * MENIMPA set izin FinanceManager, Direktur, SalesManager, Sales, Finance, Collection, dan
 * Viewer — setiap penyesuaian yang pernah dilakukan operator lewat Settings → Roles lenyap
 * tanpa peringatan. Migrasi ini hanya MENAMBAH, hanya pada SuperAdmin, dan tidak pernah
 * mencabut apa pun.
 *
 * ── Kenapa aman diulang ──
 * Hanya izin yang BELUM dipegang yang disisipkan, jadi menjalankannya dua kali tidak menggandakan
 * apa pun. Peran SuperAdmin dicari per baris (bukan satu), sebab tabel roles ber-tenant dan satu
 * pemasangan bisa memuat lebih dari satu peran bernama sama.
 */
return new class extends Migration
{
    private const PERAN = 'SuperAdmin';

    public function up(): void
    {
        foreach (['permissions', 'roles', 'role_has_permissions'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                return;
            }
        }

        $disisipkan = 0;

        foreach (DB::table('roles')->where('name', self::PERAN)->pluck('id') as $roleId) {
            $kurang = DB::table('permissions as p')
                ->leftJoin('role_has_permissions as rp', function ($j) use ($roleId): void {
                    $j->on('rp.permission_id', '=', 'p.id')->where('rp.role_id', '=', $roleId);
                })
                ->whereNull('rp.role_id')
                ->pluck('p.id');

            if ($kurang->isEmpty()) {
                continue;
            }

            DB::table('role_has_permissions')->insert(
                $kurang->map(fn ($id) => ['permission_id' => (int) $id, 'role_id' => (int) $roleId])->all(),
            );
            $disisipkan += $kurang->count();
        }

        // Ditulis lewat DB::table mentah, jadi Spatie tidak tahu cache izinnya sudah basi.
        // Tanpa baris ini perubahannya baru terlihat setelah cache kedaluwarsa sendiri — dan
        // orang yang memeriksa hasilnya akan menyimpulkan migrasinya tidak bekerja.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($disisipkan > 0) {
            echo "  {$disisipkan} izin yatim diberikan ke peran ".self::PERAN.".\n";
        }
    }

    /**
     * SENGAJA TIDAK MENCABUT APA PUN.
     *
     * Migrasi ini tidak bisa tahu izin mana yang tadinya memang belum dipegang dan mana yang
     * diberikan operator sendiri lewat Settings → Roles sesudahnya. Mencabut "yang tadi
     * disisipkan" karena itu berarti berjudi dengan hak akses orang lain; yang paling buruk,
     * kesalahannya baru ketahuan saat seseorang kehilangan menu yang seharusnya ia punya.
     */
    public function down(): void
    {
        // tidak ada yang dibatalkan — lihat penjelasan di atas.
    }
};
