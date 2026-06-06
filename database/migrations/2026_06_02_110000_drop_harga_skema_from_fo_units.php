<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rollback kolom harga tetap (Cash/KPR/Inhouse) di fo_units. Harga per
 * cara-bayar dikembalikan ke model normalisasi: fo_skema (per lokasi/tahap) +
 * fo_unit_price_lists (harga per unit per skema), via Upload Price List.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            foreach (['harga_cash', 'harga_kpr', 'harga_inhouse'] as $col) {
                if (Schema::hasColumn('fo_units', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->decimal('harga_cash', 18, 2)->nullable()->after('harga_jual');
            $table->decimal('harga_kpr', 18, 2)->nullable()->after('harga_cash');
            $table->decimal('harga_inhouse', 18, 2)->nullable()->after('harga_kpr');
        });
    }
};
