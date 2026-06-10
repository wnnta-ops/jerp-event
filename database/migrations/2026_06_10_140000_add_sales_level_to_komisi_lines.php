<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Samakan "Level" di Skema Komisi (rumus) & Termin Komisi (kondisi) dengan
 * master Level Sales (fo_sales_levels) — tambah FK sales_level_id. Enum level
 * lama dibiarkan (non-destruktif).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_skema_komisi_line', function (Blueprint $table) {
            $table->foreignId('sales_level_id')->nullable()->after('skema_komisi_id')
                ->constrained('fo_sales_levels')->nullOnDelete();
        });

        Schema::table('fo_termin_komisi_kondisi', function (Blueprint $table) {
            $table->foreignId('sales_level_id')->nullable()->after('termin_komisi_id')
                ->constrained('fo_sales_levels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fo_skema_komisi_line', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_level_id');
        });
        Schema::table('fo_termin_komisi_kondisi', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_level_id');
        });
    }
};
