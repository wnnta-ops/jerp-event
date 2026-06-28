<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booked Revenue — tambah Harga (gross, termasuk PPN) & Diskon/Free DP.
 * Nilai Revenue (amount) = (gross_price − discount) ÷ 1,11  → DPP (ex-PPN 11%).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_booked_revenues', function (Blueprint $t): void {
            $t->decimal('gross_price', 20, 2)->default(0); // Harga (incl. PPN)
            $t->decimal('discount', 20, 2)->default(0);    // Diskon / Free DP
        });
    }

    public function down(): void
    {
        Schema::table('bs_booked_revenues', function (Blueprint $t): void {
            $t->dropColumn(['gross_price', 'discount']);
        });
    }
};
