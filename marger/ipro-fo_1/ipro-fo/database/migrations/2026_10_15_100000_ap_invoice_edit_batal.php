<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Invoice Hutang / Reimburse / Deposit: EDIT & BATAL.
 *
 *   sebelum diproses FAT  → boleh Edit & Batal (izin ap.invoice.update / ap.invoice.cancel)
 *   sesudah diproses FAT  → terkunci; hanya Batal oleh pemegang ap.invoice.cancel-after
 *
 * "Diproses FAT" = berkas sudah diterima Finance, sudah ada Kuitansi/pembayaran/penghapusan,
 * atau jurnalnya sudah di-approve di General Ledger (BsApInvoice::alasanDiprosesFat()).
 *
 * Batal TIDAK menghapus data: status 'batal' + jejak siapa/kapan/alasannya, lalu invoice
 * di-soft-delete supaya otomatis keluar dari Aging, Kartu Hutang, Kuitansi, dan laporan.
 *
 * Izin baru lahir TANPA pemegang (selain SuperAdmin) — diberikan lewat Grup Akses BO.
 */
return new class extends Migration
{
    private const IZIN = [
        'ap.invoice.update' => ['ap', 'Invoice Hutang — Edit (sebelum diproses FAT)'],
        'ap.invoice.cancel' => ['ap', 'Invoice Hutang — Batal (sebelum diproses FAT)'],
        'ap.invoice.cancel-after' => ['ap', 'Invoice Hutang — Batal setelah diproses FAT'],
    ];

    public function up(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_ap_invoices', 'cancelled_at')) {
                $t->timestamp('cancelled_at')->nullable();
                $t->unsignedBigInteger('cancelled_by')->nullable();
                $t->string('cancel_reason', 255)->nullable();
            }
        });

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
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            if (Schema::hasColumn('bs_ap_invoices', 'cancelled_at')) {
                $t->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason']);
            }
        });
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::IZIN))->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
