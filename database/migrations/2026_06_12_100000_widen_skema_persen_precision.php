<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema bayar dibiarkan "keriting" (tidak dibulatkan): persentase termin & header
 * butuh presisi > 2 desimal. mis. DP 5% dibagi 3 termin = 1,666667% (bukan 1,67%
 * yang bikin total 5,01%). Lebarkan kolom persen ke decimal(9,6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_skema_lines', function (Blueprint $table) {
            $table->decimal('persen', 9, 6)->nullable()->change();
        });
        Schema::table('fo_skema', function (Blueprint $table) {
            $table->decimal('dp_persen', 9, 6)->default(0)->change();
            $table->decimal('ang_persen', 9, 6)->default(0)->change();
            $table->decimal('subsidi_persen', 9, 6)->default(0)->change();
            $table->decimal('diskon_persen', 9, 6)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('fo_skema_lines', function (Blueprint $table) {
            $table->decimal('persen', 5, 2)->nullable()->change();
        });
        Schema::table('fo_skema', function (Blueprint $table) {
            $table->decimal('dp_persen', 5, 2)->default(0)->change();
            $table->decimal('ang_persen', 5, 2)->default(0)->change();
            $table->decimal('subsidi_persen', 5, 2)->default(0)->change();
            $table->decimal('diskon_persen', 5, 2)->default(0)->change();
        });
    }
};
