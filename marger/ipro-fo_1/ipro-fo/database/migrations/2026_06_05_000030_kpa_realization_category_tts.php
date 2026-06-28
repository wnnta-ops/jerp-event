<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #66 — realisasi pencairan KPA per kategori retensi + tautan TTS untuk reversal aman (#65 hapus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpa_realizations', function (Blueprint $t): void {
            $t->unsignedBigInteger('retention_category_id')->nullable();
            $t->unsignedBigInteger('tts_id')->nullable(); // TTS yang ter-auto-generate
        });
    }

    public function down(): void
    {
        Schema::table('kpa_realizations', function (Blueprint $t): void {
            $t->dropColumn(['retention_category_id', 'tts_id']);
        });
    }
};
