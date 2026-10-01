<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salinan LOKASI milik kontrak MUK — satu baris per lokasi per kontrak — beserta
 * pasangannya ke unit kita.
 *
 * Inilah lapisan yang membuat progres MUK bisa dibaca per unit. MUK memberi opname dan
 * tagihan per lokasi; kita butuh tahu lokasi itu rumah yang mana. Tanpa pasangan di sini,
 * angka progres hanya bisa dibaca setingkat kontrak.
 *
 * ── KENAPA DISALIN, BUKAN DIPANGGIL SAAT LAYAR DIBUKA ──
 * Alasan yang sama dengan bs_muk_contracts: layar yang memanggil API tiap kali dimuat ikut
 * mati saat API mereka mati — dan ini layar yang dipakai memutuskan pembayaran ke
 * kontraktor. Dengan disalin, yang hilang saat gangguan hanya kesegarannya, dan itu terbaca
 * dari ditarik_pada.
 *
 * ── PASANGANNYA DIISI MANUSIA ──
 * `unit_id` NULLABLE dan kosong adalah keadaan WAJAR, bukan kesalahan. Kode kita
 * (JRK3-004-XV-20) dan nama MUK ("CENDANA JRKC2 VI No. 15") tidak punya hubungan yang bisa
 * dipercaya mesin: keduanya memuat blok dan nomor, tetapi susunannya berbeda dan ejaannya
 * bisa meleset. Mesin boleh MENGUSULKAN; yang memutuskan orang. Pasangan yang ditebak salah
 * membebankan biaya rumah A ke rumah B tanpa satu pun galat.
 *
 * Banyak lokasi MUK memang TIDAK PUNYA pasangan unit — paket infrastruktur, kanopi, site
 * instruction. Itu bukan data yang kurang; biayanya memang bukan milik satu rumah, dan harus
 * dibagi dengan cara lain.
 *
 * ── KUNCINYA (muk_id kontrak + lokasi_id) ──
 * Satu lokasi bisa muncul di beberapa kontrak (mis. rumah yang sama kena pekerjaan tambahan
 * lewat Site Instruction), dan tiap kemunculan punya nilai serta progresnya sendiri. Jadi
 * yang unik pasangan keduanya, bukan lokasi_id saja.
 *
 * CATATAN: muk_id TERBUKTI TIDAK STABIL — 1 Oktober 2026 MUK membangun ulang datanya dan
 * seluruh id bergeser, membuat salinan kontrak berisi 49 duplikat. Tabel ini sengaja
 * dibuang-dan-ditarik-ulang setiap kali itu terjadi, sehingga ketidakstabilan itu tidak
 * merusak apa pun KECUALI kolom unit_id — pasangan yang sudah disusun orang. Karena itu
 * no_kontrak dan lokasi_kode ikut disimpan: keduanya bertahan melewati pembangunan ulang,
 * dan dari situ pasangan lama bisa dipulihkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_muk_lokasi', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();

            $t->unsignedBigInteger('muk_id')->index();          // id kontrak di MUK
            $t->string('no_kontrak', 64)->nullable()->index();  // penanda yang bertahan
            $t->unsignedBigInteger('lokasi_id')->nullable();
            $t->string('lokasi_kode', 64)->nullable()->index(); // penanda yang bertahan

            $t->string('nama', 191)->nullable();
            $t->string('tipe_rumah', 64)->nullable();
            $t->string('ukuran', 32)->nullable();
            $t->string('blok', 32)->nullable();
            $t->string('no_rumah', 32)->nullable();
            $t->integer('baris_kontrak')->nullable();

            // Nilai uang decimal(18,2) mengikuti dokumen MUK yang mengirim 2 desimal.
            $t->decimal('nilai_kontrak', 18, 2)->default(0);
            $t->decimal('opname_nilai', 18, 2)->default(0);
            $t->decimal('opname_persen', 8, 2)->default(0);
            $t->date('opname_terakhir')->nullable();
            $t->decimal('ditagih_nilai', 18, 2)->default(0);
            $t->decimal('ditagih_persen', 8, 2)->default(0);
            $t->decimal('belum_ditagih', 18, 2)->default(0);
            $t->decimal('retensi_nilai', 18, 2)->default(0);

            // Pasangan ke unit kita — lihat catatan di atas. nullOnDelete, bukan cascade:
            // unit yang terhapus tidak boleh ikut menghapus catatan progres dari MUK.
            $t->unsignedBigInteger('unit_id')->nullable()->index();
            $t->unsignedBigInteger('dipasangkan_oleh')->nullable();
            $t->timestamp('dipasangkan_pada')->nullable();

            $t->timestamp('ditarik_pada')->nullable();
            $t->timestamps();

            $t->unique(['muk_id', 'lokasi_id'], 'bs_muk_lokasi_kontrak_lokasi_unik');
            $t->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_muk_lokasi');
    }
};
