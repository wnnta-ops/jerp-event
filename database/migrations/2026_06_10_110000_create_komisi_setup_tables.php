<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KOMISI — fondasi Setup (replika eProperty legacy):
 *  - fo_tipe_tarif         : "Setup Tarif" / Tipe Biaya (PPH, BPHTB) — nilai Rp/%
 *  - fo_skema_komisi       : "Skema Komisi" header (periode, project, tipe sales,
 *                            lokasi/tahap, rumus, dasar perhitungan)
 *  - fo_skema_komisi_line  : rumus komisi per level (Kantor/Koordinator/Sales)
 *  - fo_skema_komisi_biaya : biaya-biaya yang dipotong (link ke fo_tipe_tarif)
 *  - fo_termin_komisi       : "Termin Komisi" header (skema, cara bayar)
 *  - fo_termin_komisi_kondisi : kondisi pencairan per level (% cair + syarat cair)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Setup Tarif / Tipe Biaya
        Schema::create('fo_tipe_tarif', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('kode', 32);
            $table->string('nama');                                  // PPH, BPHTB, dst
            $table->decimal('nilai_biaya', 18, 4)->default(0);       // besarnya tarif
            $table->enum('satuan', ['RP', 'PERSEN'])->default('PERSEN');
            $table->decimal('nilai_pengurang', 18, 2)->default(0);   // pengurang basis (mis. NJOPTKP BPHTB)
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'kode']);
        });

        // 2) Skema Komisi (header)
        Schema::create('fo_skema_komisi', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('kode', 32);
            $table->string('nama');
            $table->date('periode_dari')->nullable();
            $table->date('periode_sampai')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('sales_type_id')->nullable()->constrained('fo_sales_types')->nullOnDelete();
            $table->foreignId('lokasi_id')->nullable()->constrained('fo_lokasi')->nullOnDelete();
            $table->foreignId('tahap_id')->nullable()->constrained('fo_tahap')->nullOnDelete();
            $table->enum('rumus', ['SATUAN_PER_UNIT', 'TARGET_KUMULATIF', 'PROGRESIF'])->default('SATUAN_PER_UNIT');
            $table->enum('dasar_hitung', ['DPP', 'NILAI_KONTRAK'])->default('DPP');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'kode']);
        });

        // 3) Skema Komisi — rumus per level
        Schema::create('fo_skema_komisi_line', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skema_komisi_id')->constrained('fo_skema_komisi')->cascadeOnDelete();
            $table->enum('level', ['KANTOR', 'KOORDINATOR', 'SALES'])->default('SALES');
            $table->enum('tipe_tarif', ['PERSEN', 'RP'])->default('PERSEN');
            $table->decimal('tarif', 18, 4)->default(0);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();
        });

        // 4) Skema Komisi — biaya-biaya (link tipe tarif)
        Schema::create('fo_skema_komisi_biaya', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skema_komisi_id')->constrained('fo_skema_komisi')->cascadeOnDelete();
            $table->foreignId('tipe_tarif_id')->constrained('fo_tipe_tarif')->cascadeOnDelete();
            $table->timestamps();
        });

        // 5) Termin Komisi (header)
        Schema::create('fo_termin_komisi', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('kode', 32);
            $table->string('nama');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('sales_type_id')->nullable()->constrained('fo_sales_types')->nullOnDelete();
            $table->foreignId('skema_komisi_id')->nullable()->constrained('fo_skema_komisi')->nullOnDelete();
            $table->enum('cara_bayar', ['SEMUA', 'KPR', 'CASH_BERTAHAP', 'CASH_KERAS'])->default('SEMUA');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'kode']);
        });

        // 6) Termin Komisi — kondisi pencairan per level
        Schema::create('fo_termin_komisi_kondisi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('termin_komisi_id')->constrained('fo_termin_komisi')->cascadeOnDelete();
            $table->enum('level', ['KANTOR', 'KOORDINATOR', 'SALES'])->default('SALES');
            $table->string('nama')->nullable();
            $table->decimal('persen_cair', 8, 4)->default(0);   // % komisi yang cair di termin ini
            // Syarat cair (centang + nilai % nya)
            $table->boolean('use_lunas')->default(false);
            $table->decimal('persen_lunas', 8, 4)->default(0);
            $table->boolean('use_bf')->default(false);
            $table->decimal('persen_bf', 8, 4)->default(0);
            $table->boolean('use_dp')->default(false);
            $table->decimal('persen_dp', 8, 4)->default(0);
            $table->boolean('use_ang')->default(false);
            $table->decimal('persen_ang', 8, 4)->default(0);
            // Syarat dokumen
            $table->boolean('syarat_ppjb')->default(false);
            $table->boolean('syarat_ajb')->default(false);
            $table->boolean('syarat_akad')->default(false);
            // Semua syarat harus terpenuhi vs salah satu
            $table->enum('tipe_syarat', ['SEMUA', 'SALAH_SATU'])->default('SALAH_SATU');
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_termin_komisi_kondisi');
        Schema::dropIfExists('fo_termin_komisi');
        Schema::dropIfExists('fo_skema_komisi_biaya');
        Schema::dropIfExists('fo_skema_komisi_line');
        Schema::dropIfExists('fo_skema_komisi');
        Schema::dropIfExists('fo_tipe_tarif');
    }
};
