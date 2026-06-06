<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Setup NUP → master "Jenis Tipe NUP" per project (legacy: Minimum
        // Pendaftaran NUP — Tipe + Nilai). Nilai = nominal UTJ yang mengikat
        // saat pendaftaran publik.
        Schema::create('fo_nup_tipe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('nama');                          // mis. "Reguler", "CLUSTER 3 TAHAP 4"
            $table->decimal('nilai_utj', 18, 2)->default(0); // nominal UTJ
            $table->string('keterangan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'project_id', 'nama']);
            $table->index(['tenant_id', 'project_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_nup_tipe');
    }
};
