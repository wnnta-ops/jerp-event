<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Dikenakan ke" untuk Tipe Tarif (SEMUA setup komisi dari Setup Tarif):
 *  - BASIS  : penentu DPP (PPN) → DPP = harga ÷ (1 + %)   (mis. PPN 11%)
 *  - DPP    : biaya pemotong basis sebelum komisi (BPHTB, SSP)
 *  - KOMISI : pajak atas komisi (PPh 23) — dipotong dari komisi
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_tipe_tarif', function (Blueprint $table) {
            $table->enum('dikenakan', ['BASIS', 'DPP', 'KOMISI'])->default('DPP')->after('satuan');
        });
    }

    public function down(): void
    {
        Schema::table('fo_tipe_tarif', function (Blueprint $table) {
            $table->dropColumn('dikenakan');
        });
    }
};
