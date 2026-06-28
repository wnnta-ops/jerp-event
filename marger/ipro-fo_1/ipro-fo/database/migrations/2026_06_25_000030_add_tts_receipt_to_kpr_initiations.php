<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inisiasi KPR R1 — sumber inisiasi diganti dari Kuitansi → TTS (Tanda Terima Setoran).
 * Tambah kolom tts_receipt_id (nullable FK). kuitansi_id dipertahankan (nullable) utk data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_initiations', function (Blueprint $table) {
            if (! Schema::hasColumn('kpr_initiations', 'tts_receipt_id')) {
                $table->foreignId('tts_receipt_id')->nullable()
                    ->constrained('tts_receipts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpr_initiations', function (Blueprint $table) {
            if (Schema::hasColumn('kpr_initiations', 'tts_receipt_id')) {
                $table->dropConstrainedForeignId('tts_receipt_id');
            }
        });
    }
};
