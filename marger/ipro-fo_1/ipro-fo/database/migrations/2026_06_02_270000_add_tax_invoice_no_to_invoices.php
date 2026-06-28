<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor Seri Faktur Pajak (NSFP) untuk rekap PPN / e-Faktur.
 *   AP → Faktur Pajak Masukan (NSFP dari supplier)
 *   AR → Faktur Pajak Keluaran (NSFP terbit perusahaan)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            $t->string('tax_invoice_no', 30)->nullable();
        });
        Schema::table('bs_ar_invoices', function (Blueprint $t): void {
            $t->string('tax_invoice_no', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_invoices', fn (Blueprint $t) => $t->dropColumn('tax_invoice_no'));
        Schema::table('bs_ar_invoices', fn (Blueprint $t) => $t->dropColumn('tax_invoice_no'));
    }
};
