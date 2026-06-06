<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Setup Unit → master Tahap. Satu Lokasi punya banyak Tahap.
        // Kode Tahap jadi segmen kedua No. Unit (Lokasi-Tahap-Blok-Nomer).
        Schema::create('fo_tahap', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('lokasi_id')->constrained('fo_lokasi')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('kode', 32);
            $table->string('nama');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'lokasi_id', 'kode']);
            $table->index(['tenant_id', 'lokasi_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_tahap');
    }
};
