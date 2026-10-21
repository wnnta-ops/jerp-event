<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin khusus untuk tombol aksi yang dulu HANYA dijaga izin umum
 * ('businesssupport.setup.manage' / 'businesssupport.approval.act').
 *
 * 21 Okt 2026: 129 tombol (simpan/ubah/hapus/setujui/tolak/tutup …) tidak mengikuti centang di
 * bagiannya sendiri di Grup Akses BO — pengguna sudah dicentang tetapi tombol tidak muncul atau
 * 403. Kini tiap tombol menerima izin KHUSUS-nya (sebagian besar sudah ada di katalog) ATAU
 * izin umum lama. Migrasi ini menambah 7 izin yang belum ada.
 *
 * Izin baru lahir TANPA pemegang (selain SuperAdmin) — diberikan lewat Grup Akses BO.
 */
return new class extends Migration
{
    private const IZIN = [
        'ap.kuitansi.approve' => ['ap', 'Kuitansi Hutang — Setujui'],
        'ap.kuitansi.reject' => ['ap', 'Kuitansi Hutang — Tolak'],
        'cashbook.approval-retensi.reject' => ['cashbook', 'Approval Retensi — Tolak / Pulihkan'],
        'budget.upload.create' => ['budget', 'Upload Budget — Unggah'],
        'cashbook.cashflow-plan.manage' => ['cashbook', 'Rencana Cash Flow — Kelola'],
        'cashbook.signature-barcode.manage' => ['cashbook', 'Barcode Tanda Tangan — Kelola'],
        'cashbook.debit-card.approve' => ['cashbook', 'Debit Card — Setujui / Tolak permintaan'],
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
