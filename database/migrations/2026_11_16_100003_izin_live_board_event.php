<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin halaman Live Board modul Event (event.live_board.index), diberikan ke
 * peran super. Peran yang belum ada dilewati (DB baru: migrasi sebelum seeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('permissions')->where('name', 'event.live_board.index')->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'event.live_board.index', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
            ]);

        foreach (DB::table('roles')->where('guard_name', 'web')->whereIn('name', (array) config('akses.peran_super', []))->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin tidak dihapus kembali — bisa jadi sudah dicentang manual di Grup Akses.
    }
};
