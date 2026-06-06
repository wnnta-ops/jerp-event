<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            // Rekomendasi unit dari tim verifikasi data (diisi saat approve).
            // Ikut mengalir ke SPRS pada kontrak, seperti pilihan unit pendaftar.
            $table->string('rekomendasi_unit', 255)->nullable()->after('pilihan_unit_3');
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropColumn('rekomendasi_unit');
        });
    }
};
