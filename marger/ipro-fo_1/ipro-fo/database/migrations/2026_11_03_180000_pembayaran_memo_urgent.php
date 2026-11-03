<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Memo Urgent dibayar di Registrasi Pengeluaran Kas & Bank (AP) sebelum invoice hutangnya ada:
 *  - Kuitansi memo: `memo_internal_id` + `advance_account_id` (akun uang muka yang didebit).
 *  - Invoice yang menyusul dibayar dengan memotong uang muka itu: baris kuitansi mencatat
 *    `um_amount` (bagian yang ditutup uang muka) dan `um_kuitansi_id` (kuitansi memo asalnya).
 *  - Parameter memo.batas_hari_invoice: lewat N hari tanpa invoice → pengaju tidak bisa
 *    mengajukan memo baru (default 14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_kuitansis', function (Blueprint $table): void {
            if (! Schema::hasColumn('bs_ap_kuitansis', 'memo_internal_id')) {
                $table->unsignedBigInteger('memo_internal_id')->nullable()->index()->after('supplier_id');
                $table->unsignedBigInteger('advance_account_id')->nullable()->after('cash_account_id');
            }
        });
        Schema::table('bs_ap_kuitansi_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('bs_ap_kuitansi_lines', 'um_amount')) {
                $table->decimal('um_amount', 20, 2)->default(0)->after('amount');
                $table->unsignedBigInteger('um_kuitansi_id')->nullable()->index()->after('um_amount');
            }
        });

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('parameters')->insertOrIgnore([
                'tenant_id' => $tenantId, 'group' => 'memo', 'key' => 'batas_hari_invoice', 'value' => '14',
                'data_type' => 'int', 'label' => 'Batas hari invoice menyusul Memo Urgent yang sudah dibayar',
                'is_public' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('bs_ap_kuitansi_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('bs_ap_kuitansi_lines', 'um_amount')) {
                $table->dropColumn(['um_amount', 'um_kuitansi_id']);
            }
        });
        Schema::table('bs_ap_kuitansis', function (Blueprint $table): void {
            if (Schema::hasColumn('bs_ap_kuitansis', 'memo_internal_id')) {
                $table->dropColumn(['memo_internal_id', 'advance_account_id']);
            }
        });
        DB::table('parameters')->where('group', 'memo')->where('key', 'batas_hari_invoice')->delete();
    }
};
