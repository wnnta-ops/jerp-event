<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan perpindahan satu rumah dari Persediaan Dalam Proses ke Barang Siap Dijual.
 *
 * ── KENAPA PERLU TABEL, BUKAN SEKADAR JURNAL ──
 *
 * Perpindahan ini adalah jurnal: Dr Persediaan Bangunan Siap Jual / Cr Persediaan Dalam
 * Proses. Tanpa catatan tersendiri, tidak ada yang menahan rumah yang sama dipindahkan dua
 * kali — dan dua jurnal yang sama persis sulit dibedakan dari dua rumah yang kebetulan
 * bernilai sama. Indeks unik di sini yang menahannya, bukan kewaspadaan operator.
 *
 * ── NILAINYA DIBEKUKAN SAAT DIPINDAH ──
 *
 * `nilai` adalah jumlah yang benar-benar dijurnal, disalin saat perpindahan. Angka sumbernya
 * (ditagih_nilai di bs_muk_lokasi) masih bisa bertambah sesudahnya karena MUK terus menagih;
 * kalau layar menghitung ulang dari sumbernya, catatan ini akan berbeda dari jurnalnya
 * sendiri dan tidak ada yang tahu mana yang benar.
 *
 * Selisih yang muncul sesudah perpindahan BUKAN kesalahan: itu biaya yang datang belakangan,
 * dan penanganannya keputusan akuntansi tersendiri — yang penting ia terlihat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_unit_siap_jual', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();

            $t->unsignedBigInteger('unit_id');
            $t->string('lokasi_kode', 64)->nullable();

            $t->date('tanggal');
            $t->decimal('nilai', 18, 2);

            // Akun asal & tujuan disimpan apa adanya: config dan COA Project bisa berubah,
            // dan catatan lama harus tetap menyebut akun yang benar-benar dipakai saat itu.
            $t->unsignedBigInteger('akun_wip')->nullable();
            $t->unsignedBigInteger('akun_siap_jual')->nullable();

            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('dibuat_oleh')->nullable();
            $t->text('catatan')->nullable();
            $t->timestamps();

            // Satu rumah sekali pindah. Inilah penahan yang sebenarnya.
            $t->unique(['tenant_id', 'unit_id'], 'bs_unit_siap_jual_unik');
            $t->foreign('unit_id')->references('id')->on('units')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_unit_siap_jual');
    }
};
