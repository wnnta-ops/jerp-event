<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hapus Jurnal LANGSUNG (Detail Transaksi Jurnal): jurnal Final/Approved yang tidak dimiliki
 * dokumen dihapus tanpa mundur bertahap (permintaan ACC 23 Okt 2026 — jurnal dobel input).
 * Hapus bertahap (gl.journal.delete, status Baru) tetap ada.
 *
 * Izin baru lahir TANPA pemegang (selain SuperAdmin) — diberikan lewat Grup Akses BO.
 */
return new class extends Migration
{
    private const IZIN = [
        'gl.journal.delete-direct' => ['gl', 'Jurnal — Hapus langsung (Final/Approved, bukan milik dokumen)'],
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
