<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hapus kolom enum `level` lama di line/kondisi Komisi — sudah digantikan
 * sepenuhnya oleh sales_level_id (master Level Sales).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['fo_skema_komisi_line', 'fo_termin_komisi_kondisi', 'fo_skema_reward_line'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('level');
            });
        }
    }

    public function down(): void
    {
        foreach (['fo_skema_komisi_line', 'fo_termin_komisi_kondisi', 'fo_skema_reward_line'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->enum('level', ['KANTOR', 'KOORDINATOR', 'SALES'])->nullable()->after('sales_level_id');
            });
        }
    }
};
