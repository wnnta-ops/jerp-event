<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul CASH FLOW (Cash Book) — taksonomi & angka rencana arus kas.
 *
 * SENGAJA terpisah dari bs_cash_flow_categories (Direct/Indirect × Operasi/Investasi/Pendanaan)
 * milik laporan arus kas PSAK di General Ledger: struktur di sini mengikuti kebutuhan treasury
 * (INFLOW OPS / INFLOW NON OPS / OUTFLOW berikut grup KONTRAKTOR, LEGAL & LANDBANK, dst) yang
 * tidak punya padanan alami di skema PSAK. Memisahkannya menjaga laporan lama tetap utuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Kategori arus kas (Set Up Kategori).
        Schema::create('bs_cf_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('section', 20);              // inflow_ops | inflow_non_ops | outflow
            $table->string('group_name', 60)->nullable(); // grup pada OUTFLOW (KONTRAKTOR, dst)
            // 64 (bukan 32): nama kategori panjang & mirip ("… Cluster 3 T3" vs "… Cluster 3 T4"),
            // kode yang dipangkas pendek membuat keduanya bertabrakan dan saling menimpa.
            $table->string('code', 64);
            $table->string('name', 150);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code'], 'bs_cf_categories_tenant_code_unique');
            $table->index(['tenant_id', 'section']);
        });

        // Angka rencana per kategori per periode: proyeksi (cashplan) & WNPB.
        Schema::create('bs_cf_figures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('cf_category_id')->constrained('bs_cf_categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->decimal('projection', 20, 2)->default(0);  // proyeksi cashplan
            $table->decimal('wnpb', 20, 2)->default(0);        // proyeksi WNPB tahun bersangkutan
            $table->timestamps();
            $table->unique(['tenant_id', 'cf_category_id', 'period_year', 'period_month'], 'bs_cf_figures_unique');
        });

        // Rumus (Set Up Rumus) — definisi yang dipakai laporan; keterangannya bisa disunting.
        Schema::create('bs_cf_formulas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 80);
            $table->string('expression', 190);          // rumus ringkas
            $table->text('description')->nullable();    // penjelasan (boleh disunting operator)
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'bs_cf_formulas_tenant_code_unique');
        });

        // Kategori arus kas pada tiap baris "Pembagian Cash Flow" di Rekening Ledger —
        // inilah sumber angka AKTUAL per kategori pada laporan.
        Schema::table('bs_ledger_cluster_splits', function (Blueprint $table) {
            $table->foreignId('cf_category_id')->nullable()->after('cluster_id')
                ->constrained('bs_cf_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bs_ledger_cluster_splits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cf_category_id');
        });
        Schema::dropIfExists('bs_cf_formulas');
        Schema::dropIfExists('bs_cf_figures');
        Schema::dropIfExists('bs_cf_categories');
    }
};
