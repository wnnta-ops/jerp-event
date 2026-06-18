<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faktur Penjualan otomatis per kontrak (saat unit TERJUAL / kontrak SPR). Tambah
 * relasi ke kontrak + tanggal faktur. (Model fo_fp dipakai ulang sbg Faktur Penjualan.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_fp', function (Blueprint $table) {
            $table->foreignId('kontrak_id')->nullable()->after('tts_id')->constrained('fo_kontrak')->nullOnDelete();
            $table->date('tgl_faktur')->nullable()->after('tgl_terima');
        });
    }

    public function down(): void
    {
        Schema::table('fo_fp', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kontrak_id');
            $table->dropColumn('tgl_faktur');
        });
    }
};
