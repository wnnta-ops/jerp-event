<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #146 — Tautan TTS pembayaran SSP/BPHTB pada realisasi pencairan KPR.
 * Dipakai untuk reversal aman saat realisasi dihapus (lihat KprRealization::booted deleting hook).
 * (Migrasi terpisah karena 2026_06_16_000010 sudah ter-run sebelum kolom ini ditambahkan.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_realizations', function (Blueprint $t): void {
            if (! Schema::hasColumn('kpr_realizations', 'ssp_tts_id')) {
                $t->unsignedBigInteger('ssp_tts_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpr_realizations', function (Blueprint $t): void {
            if (Schema::hasColumn('kpr_realizations', 'ssp_tts_id')) {
                $t->dropColumn('ssp_tts_id');
            }
        });
    }
};
