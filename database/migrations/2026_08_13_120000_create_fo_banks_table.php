<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Setup Bank → master bank KPR per Cluster (Lokasi) + Tahap. Sumber dropdown
        // "Bank Pilihan" di form pendaftaran NUP publik (hanya saat Cara Bayar = KPR).
        // lokasi_id/tahap_id nullable = bank berlaku untuk seluruh project.
        Schema::create('fo_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('lokasi_id')->nullable()->constrained('fo_lokasi')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('tahap_id')->nullable()->constrained('fo_tahap')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('nama', 100);                      // mis. "BTN", "BCA", "Mandiri"
            $table->unsignedInteger('urutan')->default(0);    // angka kecil tampil lebih dulu
            $table->boolean('is_active')->default(true);
            $table->string('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'project_id', 'lokasi_id', 'tahap_id', 'is_active'], 'fo_banks_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_banks');
    }
};
