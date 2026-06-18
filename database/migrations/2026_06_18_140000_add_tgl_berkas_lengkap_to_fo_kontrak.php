<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tgl Berkas Lengkap (untuk Free DP / KPR FREE DP): DP yang jatuh temponya SETELAH
 * tanggal ini di-free (jadi UANG MASUK). Null = berkas lengkap di awal → semua DP free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $table) {
            $table->date('tgl_berkas_lengkap')->nullable()->after('tgl_kontrak');
        });
    }

    public function down(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $table) {
            $table->dropColumn('tgl_berkas_lengkap');
        });
    }
};
