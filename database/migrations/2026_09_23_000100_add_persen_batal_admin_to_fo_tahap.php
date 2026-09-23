<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persen biaya administrasi pembatalan, per Cluster+Tahap.
 *
 * fo_tahap memang sudah satu baris per kombinasi cluster+tahap — ia punya lokasi_id — jadi
 * di sinilah tempatnya, bukan sebagai daftar di tabel settings. Menyimpannya di sini juga
 * berarti kolomnya bisa disunting lewat tabel biasa, bukan lewat form yang harus menebak
 * kombinasi mana saja yang ada.
 *
 * NULLABLE dengan sengaja: null berarti "ikut persen bawaan" (collection.batal_admin_persen),
 * bukan "nol persen". Memberi nilai bawaan 0 pada kolom akan diam-diam membuat seluruh
 * pembatalan tidak dipotong biaya administrasi sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fo_tahap', 'persen_batal_admin')) {
            return;
        }

        Schema::table('fo_tahap', function (Blueprint $t) {
            $t->decimal('persen_batal_admin', 6, 2)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fo_tahap', 'persen_batal_admin')) {
            return;
        }

        Schema::table('fo_tahap', function (Blueprint $t) {
            $t->dropColumn('persen_batal_admin');
        });
    }
};
