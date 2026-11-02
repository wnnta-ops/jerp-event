<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Halaman baru FO "Reschedule Tagihan" (fo.reschedule_tagihan.*). Kotak izinnya dibuat di
 * sini, dan grup yang sudah boleh membuka Adjustment Kontrak langsung diberi izin Lihat —
 * keduanya pekerjaan tim yang sama, jadi menu barunya tidak tersembunyi untuk mereka.
 */
return new class extends Migration
{
    public function up(): void
    {
        $idIndex = null;
        foreach (array_keys(config('access_matrix.actions')) as $aksi) {
            $nama = 'fo.reschedule_tagihan.'.$aksi;
            $id = DB::table('permissions')->where('name', $nama)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $nama, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
                ]);
            if ($aksi === config('akses.aksi.viewAny', 'index')) {
                $idIndex = $id;
            }
        }

        $idAdjustment = DB::table('permissions')->where('name', 'fo.adjustment_kontrak.index')->value('id');
        if ($idIndex && $idAdjustment) {
            foreach (DB::table('role_has_permissions')->where('permission_id', $idAdjustment)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $idIndex, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Izin tidak dihapus kembali — bisa jadi sudah dicentang manual di Grup Akses.
    }
};
