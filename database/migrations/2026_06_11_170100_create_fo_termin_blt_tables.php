<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Termin BLT — kondisi pencairan BLT per level (syarat BF/DP/ANG/AJB + tipe syarat). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_termin_blt', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('kode')->nullable();
            $table->string('nama');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('sales_type_id')->nullable()->constrained('fo_sales_types')->nullOnDelete();
            $table->foreignId('skema_blt_id')->nullable()->constrained('fo_skema_blt')->nullOnDelete();
            $table->string('cara_bayar')->default('SEMUA');   // SEMUA/KPR/CASH_BERTAHAP/CASH_KERAS
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'kode']);
        });

        Schema::create('fo_termin_blt_kondisi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('termin_blt_id')->constrained('fo_termin_blt')->cascadeOnDelete();
            $table->foreignId('sales_level_id')->nullable()->constrained('fo_sales_levels')->nullOnDelete();
            $table->string('nama')->nullable();
            $table->decimal('persen_cair', 8, 2)->default(100);
            // Syarat Cair: BF / DP / ANG (checkbox + tipe persen/rupiah + nilai), AJB (checkbox)
            $table->boolean('use_bf')->default(false);
            $table->string('tipe_bf')->default('RP');
            $table->decimal('nilai_bf', 19, 4)->default(0);
            $table->boolean('use_dp')->default(false);
            $table->string('tipe_dp')->default('RP');
            $table->decimal('nilai_dp', 19, 4)->default(0);
            $table->boolean('use_ang')->default(false);
            $table->string('tipe_ang')->default('RP');
            $table->decimal('nilai_ang', 19, 4)->default(0);
            $table->boolean('syarat_ajb')->default(false);
            $table->string('tipe_syarat')->default('SALAH_SATU');  // SEMUA/SALAH_SATU
            $table->integer('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_termin_blt_kondisi');
        Schema::dropIfExists('fo_termin_blt');
    }
};
