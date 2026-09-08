<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor Faktur Pajak & NPWP untuk tiap BARIS JURNAL berakun PPN — Masukan maupun Keluaran.
 *
 * KENAPA TABEL TERSENDIRI, BUKAN KOLOM PADA INVOICE. Kedua daftar Faktur Pajak kini dibentuk
 * dari mutasi akun PPN di buku besar (10105010101 Masukan, 20104040406 Keluaran), bukan dari
 * tabel invoice — sebab PPN bisa lahir dari jalur yang tidak berinvoice: jurnal memorial,
 * koreksi, impor jurnal, konversi UTJ/DP, pelepasan DAJAM. Barisnya karena itu berupa BARIS
 * JURNAL, dan nomor fakturnya harus menempel pada baris itu, bukan pada invoice yang belum
 * tentu ada.
 *
 * NPWP juga disimpan DI SINI, bukan diambil dari master supplier/customer: NPWP pada faktur
 * adalah yang tertera di dokumen fakturnya, dan menuliskannya balik ke master akan mengubah
 * NPWP seluruh faktur lain milik pihak yang sama — termasuk yang sudah dilaporkan.
 *
 * SATU TABEL UNTUK DUA JENIS, bukan dua tabel kembar: isinya identik dan pembedanya hanya akun
 * mana yang dimutasi. `jenis` disimpan supaya daftar bisa disaring tanpa harus ikut menjoin
 * baris jurnalnya, tetapi keunikan tetap dijaga pada journal_line_id — satu baris jurnal hanya
 * menyentuh satu akun, jadi ia tidak mungkin bermakna dua jenis sekaligus.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_faktur_pajak_line_entries')) {
            return;
        }

        Schema::create('bs_faktur_pajak_line_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->default(1)->index();
            $t->string('jenis', 10)->index();   // masukan | keluaran
            // Satu baris jurnal hanya boleh punya satu entri faktur.
            $t->foreignId('journal_line_id')->unique()->constrained('bs_journal_lines')->cascadeOnDelete();
            $t->unsignedBigInteger('journal_id')->index();
            $t->string('fp_number', 30)->nullable();
            $t->string('npwp', 30)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_faktur_pajak_line_entries');
    }
};
