<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harga per cara-bayar langsung di fo_units (Cash / KPR / Inhouse) supaya
 * sekali upload Excel semua harga ikut masuk tanpa tabel pricelist terpisah.
 * Tetap nullable; bila kosong, viewer fallback ke pricelist skema / harga_jual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->decimal('harga_cash', 18, 2)->nullable()->after('harga_jual');
            $table->decimal('harga_kpr', 18, 2)->nullable()->after('harga_cash');
            $table->decimal('harga_inhouse', 18, 2)->nullable()->after('harga_kpr');
        });
    }

    public function down(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->dropColumn(['harga_cash', 'harga_kpr', 'harga_inhouse']);
        });
    }
};
