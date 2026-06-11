<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Skema BLT — aturan rumus BLT per (project, tipe sales, tipe unit) + baris per level. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_skema_blt', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('kode')->nullable();
            $table->string('nama');
            $table->date('periode_dari')->nullable();
            $table->date('periode_sampai')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('sales_type_id')->nullable()->constrained('fo_sales_types')->nullOnDelete();
            $table->foreignId('unit_type_id')->nullable()->constrained('fo_unit_types')->nullOnDelete();
            $table->string('rumus')->default('SATUAN_PER_UNIT');   // SATUAN_PER_UNIT / TARGET_KUMULATIF
            $table->string('dasar_hitung')->default('DPP');        // DPP / NILAI_KONTRAK
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'kode']);
        });

        Schema::create('fo_skema_blt_line', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skema_blt_id')->constrained('fo_skema_blt')->cascadeOnDelete();
            $table->foreignId('sales_level_id')->nullable()->constrained('fo_sales_levels')->nullOnDelete();
            $table->string('tipe_tarif')->default('RP');           // PERSEN / RP
            $table->decimal('tarif', 19, 4)->default(0);
            $table->boolean('potong_komisi')->default(false);
            $table->integer('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_skema_blt_line');
        Schema::dropIfExists('fo_skema_blt');
    }
};
