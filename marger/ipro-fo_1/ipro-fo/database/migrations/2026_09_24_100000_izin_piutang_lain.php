<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin layar Piutang Lain, sekaligus memberikannya kepada peran SuperAdmin.
 *
 * DUA LANGKAH, dan keduanya perlu. GranularPermissionSeeder memang sudah memuat nama izin ini,
 * tetapi seeder tidak dijalankan saat rilis — yang dijalankan adalah migrasi. Tanpa langkah
 * pertama, izinnya tidak pernah lahir dan `can('ar.piutang-lain.view')` selalu false untuk
 * pengguna biasa. Tanpa langkah kedua, izinnya lahir sebagai YATIM: ada di tabel permissions,
 * tidak dipegang peran mana pun — dan karena sidebar menyaring lewat daftar nama izin, menunya
 * tidak muncul untuk siapa pun. Persis itu yang pernah menyembunyikan sub-modul Komponen COGS
 * selama dua hari (lihat migrasi 2026_09_23_100000_beri_izin_yatim_ke_superadmin).
 *
 * HANYA MENAMBAH, HANYA pada SuperAdmin, dan aman diulang. Peran lain tidak disentuh: set
 * izinnya adalah keputusan operator di Settings → Roles, dan migrasi yang ikut mengaturnya akan
 * menimpa penyesuaian yang sudah berjalan — itulah sebabnya "jalankan ulang RoleSeeder" bukan
 * jalan keluarnya (RoleSeeder memakai syncPermissions dengan daftar yang dipatok di kode untuk
 * SELURUH peran non-SuperAdmin).
 */
return new class extends Migration
{
    private const IZIN = 'ar.piutang-lain.view';

    private const PERAN = 'SuperAdmin';

    public function up(): void
    {
        foreach (['permissions', 'roles', 'role_has_permissions'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                return;
            }
        }

        $izinId = DB::table('permissions')->where('name', self::IZIN)->where('guard_name', 'web')->value('id');

        if ($izinId === null) {
            $baris = ['name' => self::IZIN, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()];

            // Kolom 'module' & 'label' ditambahkan aplikasi ini di atas skema bawaan Spatie —
            // diisi hanya bila kolomnya memang ada, supaya migrasi ini tidak pecah pada
            // pemasangan yang belum menjalankan migrasi penambah kolom itu.
            if (Schema::hasColumn('permissions', 'module')) {
                $baris['module'] = 'ar';
            }
            if (Schema::hasColumn('permissions', 'label')) {
                $baris['label'] = Str::of(self::IZIN)->after('.')->replace(['.', '-', '_'], ' ')->headline();
            }

            $izinId = DB::table('permissions')->insertGetId($baris);
        }

        foreach (DB::table('roles')->where('name', self::PERAN)->pluck('id') as $roleId) {
            $sudah = DB::table('role_has_permissions')
                ->where('role_id', $roleId)->where('permission_id', $izinId)->exists();

            if (! $sudah) {
                DB::table('role_has_permissions')->insert(['role_id' => (int) $roleId, 'permission_id' => (int) $izinId]);
            }
        }

        // Ditulis lewat DB::table mentah, jadi Spatie tidak tahu cache izinnya sudah basi.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Mencabut pemberiannya, TETAPI TIDAK menghapus baris izinnya.
     *
     * Menghapus izinnya akan ikut menghapus pemberian yang mungkin sudah dilakukan operator ke
     * peran lain lewat Settings → Roles — dan itu tidak bisa dikembalikan. Yang dibatalkan hanya
     * apa yang migrasi ini sendiri berikan.
     */
    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $izinId = DB::table('permissions')->where('name', self::IZIN)->value('id');
        if ($izinId === null) {
            return;
        }

        DB::table('role_has_permissions')
            ->where('permission_id', $izinId)
            ->whereIn('role_id', DB::table('roles')->where('name', self::PERAN)->pluck('id'))
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
