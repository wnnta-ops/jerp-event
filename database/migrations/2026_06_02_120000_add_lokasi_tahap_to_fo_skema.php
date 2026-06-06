<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ikat skema bayar ke Lokasi + Tahap. Skema bisa berbeda per lokasi/tahap dan
 * boleh lebih dari satu per lokasi/tahap. Nullable = skema berlaku umum
 * (lintas lokasi/tahap dalam proyek).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_skema', function (Blueprint $table) {
            $table->foreignId('lokasi_id')->nullable()->after('project_id')->constrained('fo_lokasi')->nullOnDelete();
            $table->foreignId('tahap_id')->nullable()->after('lokasi_id')->constrained('fo_tahap')->nullOnDelete();
            $table->index(['tenant_id', 'lokasi_id', 'tahap_id'], 'fo_skema_lokasi_tahap_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fo_skema', function (Blueprint $table) {
            $table->dropIndex('fo_skema_lokasi_tahap_idx');
            $table->dropConstrainedForeignId('lokasi_id');
            $table->dropConstrainedForeignId('tahap_id');
        });
    }
};
