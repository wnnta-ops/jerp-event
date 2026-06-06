<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            // Unit mengikuti Lokasi & Tahap (master Setup Unit). Kolom `tahap`
            // (string) tetap dipertahankan sebagai kode tahap ter-denormalisasi
            // untuk kompatibilitas (UnitCodeParser & data lama).
            $table->foreignId('lokasi_id')->nullable()->after('project_id')->constrained('fo_lokasi')->nullOnDelete();
            $table->foreignId('tahap_id')->nullable()->after('lokasi_id')->constrained('fo_tahap')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lokasi_id');
            $table->dropConstrainedForeignId('tahap_id');
        });
    }
};
