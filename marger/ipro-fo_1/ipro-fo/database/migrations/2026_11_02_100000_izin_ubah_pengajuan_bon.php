<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Edit Pengajuan Bon Sementara — ubah bon yang masih berstatus Diajukan (belum dibayar).
 *
 *   cashbook.advance.update : ubah tanggal, keperluan, nominal, departemen, cara persetujuan,
 *                             rekening tujuan pengajuan bon
 *
 * Izin baru lahir TANPA pemegang (selain SuperAdmin) — diberikan lewat Grup Akses BO.
 */
return new class extends Migration
{
    private const IZIN = [
        'cashbook.advance.update' => ['cashbook', 'Bon Sementara — Ubah Pengajuan'],
    ];

    public function up(): void
    {
        foreach (self::IZIN as $nama => [$modul, $label]) {
            $ada = DB::table('permissions')->where('name', $nama)->where('guard_name', 'web');
            $ada->exists()
                ? $ada->update(['label' => $label, 'updated_at' => now()])
                : DB::table('permissions')->insert([
                    'name' => $nama, 'guard_name' => 'web', 'module' => $modul, 'label' => $label,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
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
