<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salinan TAGIHAN (COP Owner) milik kontrak MUK — satu baris per tagihan.
 *
 * ── KENAPA DISALIN ──
 *
 * Alasan yang sama dengan bs_muk_contracts dan bs_muk_lokasi: layar yang memanggil API
 * tiap kali dimuat ikut mati saat API mereka mati, dan ini layar yang dipakai memutuskan
 * pembayaran ke kontraktor. Ditambah satu alasan yang khusus di sini: layar Tagihan Progress
 * Project menampilkan SELURUH tagihan dari seluruh kontrak sekaligus. Menariknya langsung
 * berarti 95 panggilan API untuk satu kali buka halaman.
 *
 * Yang lebih penting: tagihan di sini DIPOSTING ke AP, dan invoice hutang yang lahir darinya
 * harus menunjuk sesuatu yang tetap. Baris JSON dari panggilan API bukan sesuatu yang bisa
 * ditunjuk.
 *
 * ── INILAH JEMBATAN YANG SELAMA INI HILANG ──
 *
 * Sampai hari ini tidak ada apa pun yang menghubungkan pembayaran di BO dengan kontrak MUK:
 * invoice AP dari posting SPK menunjuk register SPK INTERNAL (bs_work_orders), dan nomornya
 * tidak pernah cocok (diperiksa 7 Okt 2026 di server test — 95 kontrak MUK, 3 SPK internal,
 * 0 nomor cocok). Dengan tabel ini, invoice AP bisa menunjuk `bs_muk_tagihan.id`, dan dari
 * situ `muk_id`-nya terbaca sampai ke kontraknya. Riwayat pembayaran di layar Informasi CTC
 * bergantung pada jembatan ini.
 *
 * ── YANG DARI MUK vs YANG DARI KITA ──
 *
 * Kolom nilai (dpp, ppn, pph, total, diterima, status) ditimpa setiap penarikan — MUK yang
 * berwenang atasnya, dan API mereka baca-saja. Kolom posting (supplier_id, due_date,
 * diposting_*) milik KITA dan TIDAK PERNAH disentuh penarikan; kalau ikut tertimpa, tagihan
 * yang sudah diposting akan muncul lagi di antrean dan dibayar dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_muk_tagihan', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();

            // Kontraknya di MUK. Bukan foreign key ke bs_muk_contracts: urutan penarikan
            // tidak dijamin, dan tagihan yang datang lebih dulu daripada kontraknya lebih
            // baik tersimpan daripada ditolak.
            $t->unsignedBigInteger('muk_id')->index();
            $t->string('no_kontrak', 64)->nullable();
            $t->string('no_tagihan', 64);
            $t->string('jenis', 32)->nullable();

            $t->date('tanggal')->nullable();
            $t->date('jatuh_tempo')->nullable();

            // PPN & PPh 4(2) ADA di sini, berbeda dengan endpoint kontrak yang tidak
            // memuatnya sama sekali. Inilah satu-satunya tempat MUK menyebut keduanya.
            $t->decimal('dpp', 18, 2)->default(0);
            $t->decimal('ppn', 18, 2)->default(0);
            $t->decimal('pph', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);
            $t->decimal('diterima', 18, 2)->default(0);
            $t->string('status', 32)->nullable();

            $t->timestamp('ditarik_pada')->nullable();

            // ── milik kita, tidak pernah ditimpa penarikan ──
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->date('due_date')->nullable();
            $t->unsignedBigInteger('diposting_oleh')->nullable();
            $t->timestamp('diposting_pada')->nullable();

            $t->timestamps();

            // Satu tagihan sekali saja. Tanpa ini, penarikan yang berjalan dua kali
            // menggandakan barisnya dan tagihan yang sama bisa diposting ke AP dua kali.
            $t->unique(['tenant_id', 'muk_id', 'no_tagihan'], 'bs_muk_tagihan_unik');
            $t->index(['tenant_id', 'diposting_pada'], 'bs_muk_tagihan_posting_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_muk_tagihan');
    }
};
