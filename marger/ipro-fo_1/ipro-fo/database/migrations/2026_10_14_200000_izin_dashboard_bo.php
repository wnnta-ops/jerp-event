<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hak akses Dashboard Back Office — satu izin per sub-dashboard.
 *
 * Sebelumnya dashboard tidak berizin: siapa pun yang masuk BO (termasuk lewat SSO dari FO)
 * mendarat di 18 grafik, termasuk saldo rekening bank perusahaan. Kini tiap sub-dashboard
 * dibuka izinnya sendiri; pengguna tanpa satu pun izin melihat halaman "Selamat Datang".
 *
 * Izin lahir TANPA pemegang (selain SuperAdmin yang lolos semua gate): pemberiannya diatur
 * admin lewat Grup Akses BO. Itu disengaja — permintaannya adalah pengguna biasa TIDAK lagi
 * melihat dashboard.
 *
 * Izin tampil menu.dashboard.* ikut dibuat di sini (katalog config/menu_permissions.php sudah
 * memuatnya, tetapi migrasi katalog 2026_10_12_200000 telanjur berjalan di server).
 */
return new class extends Migration
{
    private const IZIN = [
        'dashboard.kpr.view' => ['dashboard', 'Dashboard KPR & Collection — Lihat'],
        'dashboard.cash.view' => ['dashboard', 'Dashboard Cash Management — Lihat'],
        'dashboard.accounting.view' => ['dashboard', 'Dashboard Accounting, Tax & Budget — Lihat'],
        'dashboard.project.view' => ['dashboard', 'Dashboard Project — Lihat'],
        'menu.dashboard.kpr' => ['menu', 'Tampil: Dashboard Back Office › KPR & Collection'],
        'menu.dashboard.cash' => ['menu', 'Tampil: Dashboard Back Office › Cash Management'],
        'menu.dashboard.accounting' => ['menu', 'Tampil: Dashboard Back Office › Accounting, Tax & Budget'],
        'menu.dashboard.project' => ['menu', 'Tampil: Dashboard Back Office › Project'],
    ];

    public function up(): void
    {
        foreach (self::IZIN as $nama => [$modul, $label]) {
            if (! DB::table('permissions')->where('name', $nama)->where('guard_name', 'web')->exists()) {
                DB::table('permissions')->insert([
                    'name' => $nama, 'guard_name' => 'web', 'module' => $modul, 'label' => $label,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::IZIN))->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
