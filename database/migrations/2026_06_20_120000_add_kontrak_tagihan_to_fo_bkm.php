<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link BKM (kwitansi) ke baris Jadwal Tagihan tertentu — 1 pembayaran = 1 kwitansi.
 * Dipakai Pelunasan Angsuran & TDP + Pelunasan UTJ agar sisa tagihan akurat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_bkm', function (Blueprint $table) {
            $table->unsignedBigInteger('kontrak_tagihan_id')->nullable()->after('kontrak_id');
            $table->foreign('kontrak_tagihan_id')->references('id')->on('fo_kontrak_tagihan')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fo_bkm', function (Blueprint $table) {
            $table->dropForeign(['kontrak_tagihan_id']);
            $table->dropColumn('kontrak_tagihan_id');
        });
    }
};
