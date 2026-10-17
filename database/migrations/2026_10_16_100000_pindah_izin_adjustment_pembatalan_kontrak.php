<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Kotak "Adjustment Kontrak" & "Pembatalan Kontrak" di Grup Akses dulu bernama
 * fo.adjustment.* / fo.pembatalan.*, padahal halamannya memeriksa
 * fo.adjustment_kontrak.index / fo.pembatalan_kontrak.index. Mencentangnya tidak
 * membuka apa pun — menunya tetap tersembunyi.
 *
 * Kotaknya kini memakai nama yang benar. Siapa pun yang SUDAH mencentang nama lama
 * (aksi apa pun) diberi izin Lihat halaman yang benar, supaya maksud centangnya
 * berlaku. Izin lama tidak dihapus.
 */
return new class extends Migration
{
    private const PINDAH = [
        'fo.adjustment.' => 'fo.adjustment_kontrak.index',
        'fo.pembatalan.' => 'fo.pembatalan_kontrak.index',
    ];

    public function up(): void
    {
        foreach (self::PINDAH as $awalanLama => $baru) {
            $idLama = DB::table('permissions')->where('name', 'like', $awalanLama.'%')->pluck('id');
            if ($idLama->isEmpty()) {
                continue;
            }

            $idBaru = DB::table('permissions')->where('name', $baru)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $baru,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach (DB::table('role_has_permissions')->whereIn('permission_id', $idLama)->pluck('role_id')->unique() as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $idBaru, 'role_id' => $roleId]);
            }

            $model = DB::table('model_has_permissions')->whereIn('permission_id', $idLama)
                ->select('model_type', 'model_id')->distinct()->get();
            foreach ($model as $m) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $idBaru, 'model_type' => $m->model_type, 'model_id' => $m->model_id,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Pemberian izin tidak dicabut kembali — tak bisa dibedakan dari yang diberikan manual.
    }
};
