<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lengkapi fo_skema_lines untuk grid editor skema (Gambar 2):
 * - tipe: BF (booking fee) / DP / ANG (angsuran) / ADM (administrasi)
 * - jadwal_basis (BULAN/HARI/TANGGAL) + jadwal_nilai (interval/offset)
 * - setelah: termin ini mulai dihitung setelah termin ke-N
 * - flag_bf: termin dipotong/diisi dari Booking Fee (saat BF disebar)
 * - flag_kpr: termin dicairkan bank (komponen KPR), bukan DP tunai
 * (Nilai %/F tetap pakai basis PERSEN/NOMINAL + persen/nominal yang sudah ada.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_skema_lines', function (Blueprint $table) {
            $table->string('tipe', 8)->default('DP')->after('urutan');     // BF/DP/ANG/ADM
            $table->string('jadwal_basis', 8)->default('BULAN')->after('jatuh_tempo_hari'); // BULAN/HARI/TANGGAL
            $table->unsignedSmallInteger('jadwal_nilai')->default(0)->after('jadwal_basis');
            $table->unsignedSmallInteger('setelah')->default(0)->after('jadwal_nilai');
            $table->boolean('flag_bf')->default(false)->after('setelah');
            $table->boolean('flag_kpr')->default(false)->after('flag_bf');
        });
    }

    public function down(): void
    {
        Schema::table('fo_skema_lines', function (Blueprint $table) {
            $table->dropColumn(['tipe', 'jadwal_basis', 'jadwal_nilai', 'setelah', 'flag_bf', 'flag_kpr']);
        });
    }
};
