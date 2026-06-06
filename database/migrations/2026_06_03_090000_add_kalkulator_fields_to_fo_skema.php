<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lengkapi fo_skema dengan parameter yang dibutuhkan kalkulator skema (Gambar 2):
 * - subsidi_persen : "Free 20% dari developer" (rumus DP × subsidi%) untuk mode DP Disebar. Default 20.
 * - ang_persen     : total persen Angsuran (komplemen DP) untuk mode berbasis persen.
 * - bf_nominal     : nilai Booking Fee = UTJ (dari pembayaran Xendit awal / transfer manual).
 * (dp_persen sudah ada sejak skema awal.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_skema', function (Blueprint $table) {
            if (! Schema::hasColumn('fo_skema', 'subsidi_persen')) {
                $table->decimal('subsidi_persen', 5, 2)->default(20)->after('diskon_persen');
            }
            if (! Schema::hasColumn('fo_skema', 'ang_persen')) {
                $table->decimal('ang_persen', 5, 2)->default(0)->after('dp_persen');
            }
            if (! Schema::hasColumn('fo_skema', 'bf_nominal')) {
                $table->decimal('bf_nominal', 18, 2)->default(0)->after('ang_persen');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fo_skema', function (Blueprint $table) {
            foreach (['subsidi_persen', 'ang_persen', 'bf_nominal'] as $col) {
                if (Schema::hasColumn('fo_skema', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
