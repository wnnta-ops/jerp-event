<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap dokumen kontrak:
 *  - SPRS = Surat Pemesanan Rumah Sementara (awal, hasil closing NUP)
 *  - SPR  = Surat Pemesanan Rumah (final, setelah SPRS diedit & disimpan)
 * Default SPRS. Promosi ke SPR otomatis saat SPRS disimpan dari menu SPRS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $table) {
            $table->enum('tahap', ['SPRS', 'SPR'])->default('SPRS')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $table) {
            $table->dropColumn('tahap');
        });
    }
};
