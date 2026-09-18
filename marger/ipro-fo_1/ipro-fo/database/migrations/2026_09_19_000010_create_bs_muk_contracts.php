<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salinan kontrak owner dari MUK ERP — sumber angka SPK.
 *
 * DISALIN, bukan dibaca langsung tiap halaman dibuka. Kalau layar Finance memanggil API
 * MUK setiap kali dimuat, gangguan di sisi mereka menjadi gangguan di layar orang kita —
 * dan Posting Invoice SPK adalah layar yang dipakai untuk membayar orang. Dengan disalin,
 * daftarnya tetap tampil memakai hasil tarikan terakhir meski API mereka sedang mati; yang
 * hilang hanya kesegarannya, dan itu terlihat dari kolom ditarik_pada.
 *
 * muk_id = id kontrak di sisi MUK, dipakai sebagai kunci penyelarasan. Bertipe bigint,
 * cocok dengan bs_ap_invoices.source_id bila kelak invoice-nya ditautkan ke sini.
 *
 * diperbarui = stempel "terakhir diubah" milik MUK, dipakai untuk penarikan berkala
 * (?diperbarui_sejak=) supaya tidak menarik 101 kontrak setiap kali.
 *
 * Nilai uang decimal(18,2) mengikuti dokumen MUK yang mengirim 2 desimal — supaya
 * pembulatan kedua sistem sama dan tidak ada selisih koma di angka tagihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_muk_contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();

            $t->unsignedBigInteger('muk_id')->unique();
            $t->string('no_kontrak', 64)->nullable()->index();
            $t->string('no_spk', 64)->nullable();
            $t->string('jenis', 16)->nullable();          // kontrak | si
            $t->string('status', 24)->nullable()->index();

            $t->string('proyek_kode', 32)->nullable()->index();
            $t->string('proyek_nama', 191)->nullable();
            $t->string('pelanggan_kode', 32)->nullable();
            $t->string('pelanggan_nama', 191)->nullable();

            $t->date('tanggal')->nullable();
            $t->date('mulai')->nullable();
            $t->date('selesai')->nullable();

            $t->decimal('nilai_kontrak', 18, 2)->default(0);
            $t->decimal('retensi_persen', 8, 4)->default(0);

            // Kumulatif "hari ini" dari MUK. Sengaja disimpan apa adanya, tidak dihitung
            // ulang di sini: kalau kedua sistem menghitung sendiri, selisihnya baru
            // ketahuan saat angka tagihan sudah terlanjur berbeda.
            $t->decimal('kum_diopname', 18, 2)->default(0);
            $t->decimal('kum_ditagih_neto', 18, 2)->default(0);
            $t->decimal('kum_diterima', 18, 2)->default(0);
            $t->decimal('kum_retensi_ditahan', 18, 2)->default(0);

            $t->timestamp('diperbarui')->nullable()->index();   // milik MUK
            $t->timestamp('ditarik_pada')->nullable();          // milik kita
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_muk_contracts');
    }
};
