<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pasangan LOKASI MUK ↔ UNIT kita — satu baris per lokasi FISIK, bukan per baris kontrak.
 *
 * Memperbaiki rancangan di 2026_10_01_110000, yang menaruh unit_id di tiap baris
 * bs_muk_lokasi. Data sungguhan membantahnya:
 *
 *     baris rumah      : 2820
 *     lokasi_id unik   : 2819   <- hampir semuanya berbeda
 *     lokasi_kode unik : 1438
 *     unit di BO       : 1362
 *
 * MUK menerbitkan lokasi_id BARU untuk tiap baris kontrak, jadi ia bukan identitas lokasi.
 * Yang menandai rumah adalah lokasi_kode: "006005 | AKASIA JRKC2 II No. 5" muncul di EMPAT
 * kontrak — housing, lalu kanopi, lalu site instruction — dengan empat lokasi_id berbeda
 * tetapi kode yang sama. Dan 1438 kode itu mendekati 1362 unit, yang memang jumlah rumahnya.
 *
 * KALAU PASANGAN DISIMPAN PER BARIS, satu rumah harus dipasangkan empat kali — dan lebih
 * buruk: bisa dipasangkan ke unit yang BERBEDA di tiap kontrak, tanpa apa pun yang menahannya.
 * Biaya kanopi rumah A menempel ke rumah B, tanpa galat, tanpa yang tahu.
 *
 * Dengan satu baris per lokasi_kode: satu keputusan per rumah, berlaku untuk seluruh
 * kemunculannya, mustahil bertentangan dengan dirinya sendiri. Pekerjaan operator turun dari
 * 2820 menjadi ~1438.
 *
 * UNIK PER UNIT JUGA, bukan hanya per kode. Dua lokasi MUK yang menunjuk unit yang sama
 * berarti biaya rumah itu terhitung dua kali. Kalau memang MUK punya dua kode untuk satu
 * rumah, itu persoalan data di sisi mereka dan harus terlihat — bukan diterima diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_muk_unit_pasangan', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();

            $t->string('lokasi_kode', 64);
            // Nama saat dipasangkan, disalin apa adanya. Bukan untuk dipakai menyaring,
            // melainkan supaya daftar pasangan tetap terbaca ketika salinan lokasi sedang
            // kosong — dan supaya terlihat bila MUK kelak mengganti nama di balik kode yang
            // sama.
            $t->string('nama_saat_dipasangkan', 191)->nullable();

            $t->unsignedBigInteger('unit_id');

            $t->unsignedBigInteger('dipasangkan_oleh')->nullable();
            $t->timestamp('dipasangkan_pada')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'lokasi_kode'], 'bs_muk_unit_kode_unik');
            $t->unique(['tenant_id', 'unit_id'], 'bs_muk_unit_unit_unik');
            $t->foreign('unit_id')->references('id')->on('units')->cascadeOnDelete();
        });

        // Kolom pasangan di bs_muk_lokasi tidak lagi dipakai. Dibuang, bukan dibiarkan:
        // dua tempat menyimpan pasangan berarti suatu saat keduanya berbeda, dan tidak ada
        // yang tahu mana yang benar. Tabel itu baru dibuat hari ini dan belum pernah berisi
        // satu pun pasangan (diperiksa: 0), jadi tidak ada yang hilang.
        Schema::table('bs_muk_lokasi', function (Blueprint $t) {
            $t->dropForeign(['unit_id']);
            $t->dropColumn(['unit_id', 'dipasangkan_oleh', 'dipasangkan_pada']);
        });
    }

    public function down(): void
    {
        Schema::table('bs_muk_lokasi', function (Blueprint $t) {
            $t->unsignedBigInteger('unit_id')->nullable()->index();
            $t->unsignedBigInteger('dipasangkan_oleh')->nullable();
            $t->timestamp('dipasangkan_pada')->nullable();
            $t->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
        });

        Schema::dropIfExists('bs_muk_unit_pasangan');
    }
};
