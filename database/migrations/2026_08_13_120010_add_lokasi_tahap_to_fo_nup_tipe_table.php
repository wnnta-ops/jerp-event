<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jenis Tipe NUP (mis. "CLUSTER 3 TAHAP 4") kini terhubung ke master
        // Cluster + Tahap. Dipakai menyaring dropdown "Bank Pilihan" di form NUP
        // publik: bank yang tampil = milik cluster+tahap dari tipe yang dipilih.
        // Nullable → tipe lama tetap sah (bank jatuh ke lingkup project).
        Schema::table('fo_nup_tipe', function (Blueprint $table) {
            $table->foreignId('lokasi_id')->nullable()->after('project_id')
                ->constrained('fo_lokasi')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('tahap_id')->nullable()->after('lokasi_id')
                ->constrained('fo_tahap')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_tipe', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tahap_id');
            $table->dropConstrainedForeignId('lokasi_id');
        });
    }
};
