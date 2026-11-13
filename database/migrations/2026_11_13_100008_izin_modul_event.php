<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin modul Event: gerbang panel (panel.event) + resource Daftar Event
 * (event.event.*). Peran super (akses.peran_super) langsung diberi semuanya —
 * meski mereka sudah lolos lewat Akses::peranSuper, izinnya tetap dicentang
 * supaya tampil benar di Grup Akses.
 *
 * Sengaja HANYA izin Event — tidak memakai akses:izin-lengkapi --jalankan yang
 * juga membuat izin modul lain dan menyamakan seluruh izin peran super.
 * Peran yang belum ada dilewati (DB baru: migrasi jalan sebelum seeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        $nama = ['panel.event'];
        foreach (['index', 'store', 'update', 'destroy'] as $aksi) {
            $nama[] = 'event.event.'.$aksi;
        }

        $ids = [];
        foreach ($nama as $n) {
            $ids[] = DB::table('permissions')->where('name', $n)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $n, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
                ]);
        }

        $peranSuper = DB::table('roles')->where('guard_name', 'web')
            ->whereIn('name', (array) config('akses.peran_super', []))->pluck('id');
        foreach ($peranSuper as $roleId) {
            foreach ($ids as $pid) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $pid, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin tidak dihapus kembali — bisa jadi sudah dicentang manual di Grup Akses.
    }
};
