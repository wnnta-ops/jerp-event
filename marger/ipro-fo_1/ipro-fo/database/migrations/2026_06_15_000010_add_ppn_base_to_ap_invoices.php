<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #140 — pisahkan basis objek PPN (ppn_base) dari porsi non-objek PPN (mirror pph_base).
 * PPN dihitung dari ppn_base (default = seluruh DPP saat ber-PPN), bukan dari DPP penuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_ap_invoices', 'ppn_base')) {
                $t->decimal('ppn_base', 20, 2)->default(0); // nilai objek PPN
            }
        });

        // Backfill: untuk invoice ber-PPN, objek PPN default = seluruh DPP.
        if (Schema::hasColumn('bs_ap_invoices', 'ppn_base')) {
            DB::table('bs_ap_invoices')->where('ppn_mode', 'with')->update(['ppn_base' => DB::raw('dpp')]);
        }
    }

    public function down(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            if (Schema::hasColumn('bs_ap_invoices', 'ppn_base')) {
                $t->dropColumn('ppn_base');
            }
        });
    }
};
