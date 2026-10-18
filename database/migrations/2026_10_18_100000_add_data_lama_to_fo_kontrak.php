<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda kontrak hasil migrasi dari aplikasi lama (tidak tampil di layar).
 *
 * Jadwal tagihan kontrak lama dibawa APA ADANYA. Di aplikasi lama, biaya seperti Biaya KPR,
 * Biaya HT, PPJB atau Subsidi Bunga adalah tagihan TAMBAHAN di luar harga — sehingga aturan
 * J-ERP "Pencairan KPR = Nilai Kontrak − tagihan lain" dan pembentukan ulang jadwal dari Skema
 * Bayar akan mengubah angka yang sudah disepakati dengan customer dan bank. Kontrak bertanda
 * ini tidak pernah dihitung/dibentuk ulang otomatis.
 *
 * Diisi oleh skrip migrasi data lama, bukan oleh aplikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $t) {
            $t->boolean('data_lama')->default(false)->after('tahap');
        });
    }

    public function down(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $t) {
            $t->dropColumn('data_lama');
        });
    }
};
