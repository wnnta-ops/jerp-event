<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No. referensi TTS/BKM diisi dari berita transfer bank (keterangan_bank, 255) saat
 * Booked Manual pilih unit — 64 karakter tidak muat ("Data too long for column").
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['fo_tts', 'fo_bkm'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->string('no_referensi', 255)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Tidak dikecilkan lagi — data yang sudah lebih dari 64 karakter akan terpotong.
    }
};
