<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag Unit + Customer pada Invoice Hutang (AP) — telusur biaya per unit/customer,
 * dipilih sekali saat registrasi (sinkron, anti salah ketik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_ap_invoices', 'unit_id')) {
                $t->unsignedBigInteger('unit_id')->nullable();
                $t->index('unit_id');
            }
            if (! Schema::hasColumn('bs_ap_invoices', 'customer_id')) {
                $t->unsignedBigInteger('customer_id')->nullable();
                $t->index('customer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            foreach (['unit_id', 'customer_id'] as $col) {
                if (Schema::hasColumn('bs_ap_invoices', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
