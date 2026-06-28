<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #92 — nilai objek PPh (pph_base) terpisah dari total transaksi → PPh dihitung hanya dari objek pajak.
 * #94 — jenis PPh (pph21 / pph23 / pph4_2) untuk default tarif + rekap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_ap_invoices', 'pph_base')) {
                $t->decimal('pph_base', 20, 2)->default(0); // nilai objek PPh
            }
            if (! Schema::hasColumn('bs_ap_invoices', 'pph_type')) {
                $t->string('pph_type', 12)->nullable(); // pph21 / pph23 / pph4_2
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            foreach (['pph_base', 'pph_type'] as $c) {
                if (Schema::hasColumn('bs_ap_invoices', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
