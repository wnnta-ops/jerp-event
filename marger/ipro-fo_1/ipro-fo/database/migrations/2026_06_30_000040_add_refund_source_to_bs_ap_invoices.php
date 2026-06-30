<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refund dari J-ERP FO masuk Account Payable BO (hutang ke CUSTOMER):
 *  - refund_source: jenis sumber (SPR / SPRS / TRANSFER_ANONIM) → untuk filter "Sumber".
 *  - supplier_id dijadikan nullable: refund tidak punya supplier (pakai customer_id).
 * Invoice refund ditandai source_type='refund'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_ap_invoices', 'refund_source')) {
                $t->string('refund_source', 40)->nullable()->after('source_id');
                $t->index('refund_source');
            }
        });

        // Refund tak punya supplier → izinkan NULL (kolom semula NOT NULL).
        DB::statement('ALTER TABLE bs_ap_invoices MODIFY supplier_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            if (Schema::hasColumn('bs_ap_invoices', 'refund_source')) {
                $t->dropColumn('refund_source');
            }
        });
    }
};
