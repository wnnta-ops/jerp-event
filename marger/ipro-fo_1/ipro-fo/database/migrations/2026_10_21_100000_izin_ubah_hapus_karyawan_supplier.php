<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin Ubah/Hapus Karyawan & Buat/Ubah/Hapus Supplier (Data Center).
 *
 * Dulu Tambah/Simpan/Hapus di Data Karyawan & Supplier dijaga 'businesssupport.setup.manage',
 * yang tidak tampil di bagian Data Center Grup Akses BO — pengguna yang sudah mencentang
 * semua izin Karyawan tetap kena 403 saat menyimpan (21 Okt 2026). Kini aksi itu menerima
 * izin Data Center-nya sendiri; Setup — Kelola tetap berlaku.
 *
 * Izin baru lahir TANPA pemegang (selain SuperAdmin) — diberikan lewat Grup Akses BO.
 */
return new class extends Migration
{
    private const IZIN = [
        'datacenter.employee.update' => 'Karyawan — Ubah',
        'datacenter.employee.delete' => 'Karyawan — Hapus',
        'datacenter.supplier.create' => 'Supplier — Buat',
        'datacenter.supplier.update' => 'Supplier — Ubah',
        'datacenter.supplier.delete' => 'Supplier — Hapus',
    ];

    public function up(): void
    {
        foreach (self::IZIN as $nama => $label) {
            $ada = DB::table('permissions')->where('name', $nama)->where('guard_name', 'web');
            $ada->exists()
                ? $ada->update(['label' => $label, 'updated_at' => now()])
                : DB::table('permissions')->insert([
                    'name' => $nama, 'guard_name' => 'web', 'module' => 'datacenter', 'label' => $label,
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
