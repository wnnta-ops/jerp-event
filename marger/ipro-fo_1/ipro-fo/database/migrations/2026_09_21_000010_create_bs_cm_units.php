<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salinan data BAST per unit dari sistem Komplain/Garansi.
 *
 * SERAH TERIMA UNIT DICATAT DI SANA, BUKAN DI BO. Dari 1464 unit di sistem Komplain,
 * 1110 punya tanggal BAST — sementara BO hanya memegang 18 dokumen BAST. Karena itu layar
 * Detail BAST menampilkan 73 "Belum BAST" untuk rumah yang sebenarnya sudah diserahkan.
 *
 * DISALIN, bukan dipanggil tiap layar dibuka: gangguan API mereka tidak boleh mengosongkan
 * layar kontrol dokumen. Kesegarannya terbaca dari ditarik_pada.
 *
 * kode_unit disimpan APA ADANYA dari mereka, dan pencocokan ke BO dilakukan tanpa peka
 * besar-kecil huruf lewat kode_upper — BO menulis "JRS5-001-II-53a", mereka menulis
 * "…-53A". Diuji pada 27 unit yang punya booking di BO: 27 dari 27 cocok dengan cara itu,
 * 22 bila huruf ikut dibandingkan.
 *
 * TIDAK menyimpan access_code, nama/telepon pemilik, atau URL tanda tangan. access_code
 * adalah kredensial login APP Warga, dan sisanya PII yang tidak dibutuhkan layar ini.
 * Menyalin data pribadi yang tidak dipakai hanya menambah tempat ia bisa bocor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_cm_units', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();

            $t->unsignedBigInteger('cm_id')->unique();          // id unit di sistem Komplain
            $t->string('kode_unit', 64)->nullable();
            $t->string('kode_upper', 64)->nullable()->index();  // untuk mencocokkan ke BO
            $t->string('cluster_kode', 32)->nullable();
            $t->string('cluster_nama', 191)->nullable();
            $t->string('nama', 191)->nullable();

            $t->date('bast_date')->nullable()->index();
            $t->string('bast_no', 191)->nullable();
            $t->timestamp('bast_signed_at')->nullable();
            $t->string('bast_handover_name', 191)->nullable();

            $t->boolean('is_active')->default(true);
            $t->timestamp('diperbarui')->nullable()->index();   // updated_at milik mereka
            $t->timestamp('ditarik_pada')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cm_units');
    }
};
