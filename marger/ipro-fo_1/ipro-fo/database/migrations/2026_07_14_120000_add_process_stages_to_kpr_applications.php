<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap tambahan Proses KPR (Wawancara, Input Sistem Bank, Verifikasi [OTS + Wawancara Kedua],
 * Analis [+opsi banding], Banding) disimpan sebagai satu blob JSON per aplikasi — mengikuti pola
 * kolom `stages` pada kpr_initiations. Tiap tahap berisi tanggal mulai, catatan (update & kendala),
 * tanggal selesai; sub-tahap & field ekstra (berkas_tambahan, banding, jenis) ikut di dalam JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_applications', function (Blueprint $table) {
            $table->json('process_stages')->nullable()->after('timeline');
        });
    }

    public function down(): void
    {
        Schema::table('kpr_applications', function (Blueprint $table) {
            $table->dropColumn('process_stages');
        });
    }
};
