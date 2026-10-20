<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hapus Jurnal (Detail Transaksi Jurnal) + Mundur/Hapus Bon Sementara.
 *
 *   gl.journal.delete      : hapus jurnal berstatus Baru yang tidak dimiliki dokumen
 *   cashbook.advance.undo  : mundurkan bon satu langkah (Batal Refund/Reimburse → Batal
 *                            Realisasi → Batal Pembayaran → Hapus Pengajuan)
 *
 * Izin baru lahir TANPA pemegang (selain SuperAdmin) — diberikan lewat Grup Akses BO.
 * bs_cash_advance_mundur = riwayat append-only tiap langkah mundur (siapa, kapan, alasannya).
 */
return new class extends Migration
{
    private const IZIN = [
        'gl.journal.delete' => ['gl', 'Jurnal — Hapus (status Baru, bukan milik dokumen)'],
        'cashbook.advance.undo' => ['cashbook', 'Bon Sementara — Mundur langkah / Hapus pengajuan'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('bs_cash_advance_mundur')) {
            Schema::create('bs_cash_advance_mundur', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->unsignedBigInteger('cash_advance_id')->index();
                $t->string('advance_number', 50)->nullable();
                $t->string('langkah', 40);
                $t->string('dari_status', 40)->nullable();
                $t->string('alasan', 255);
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

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
        Schema::dropIfExists('bs_cash_advance_mundur');
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::IZIN))->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
