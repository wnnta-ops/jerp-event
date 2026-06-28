<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan kelengkapan berkas (free-text) per invoice hutang — menggantikan checklist
 * per-item pada rincian AP.INV. Tampil pada kolom "Kelengkapan Berkas" di daftar Invoice Hutang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('bs_ap_invoices', 'berkas_notes')) {
                $table->text('berkas_notes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('bs_ap_invoices', 'berkas_notes')) {
                $table->dropColumn('berkas_notes');
            }
        });
    }
};
