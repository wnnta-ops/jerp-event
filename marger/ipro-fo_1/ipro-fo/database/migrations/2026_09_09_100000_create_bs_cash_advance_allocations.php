<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ALOKASI KASBON — pengeluaran bertahap atas sebuah bon yang dibayar lewat Debit VA.
 *
 * KENAPA ADA. Pembayaran Debit VA tidak lagi membentuk jurnal: uangnya baru berpindah dari bank
 * perusahaan ke tangan pemegang bon saat kartunya benar-benar dipakai, dan itu bisa terjadi
 * beberapa kali pada tanggal yang berbeda dari bank yang berbeda pula. Satu bon karena itu
 * melahirkan BEBERAPA pengeluaran, dan tiap pengeluaran itulah yang berjurnal:
 *
 *     Dr Uang Muka Operasional  /  Cr bank pengeluaran yang dipilih
 *
 * Baris di sini adalah SATU-SATUNYA dasar dua angka sekaligus: "Pemakaian" kartu pada Set Up
 * Credit Card, dan besaran Uang Muka Operasional yang kemudian direalisasi bonnya. Menyimpannya
 * hanya sebagai jurnal tidak cukup — jurnal tidak tahu alokasi itu milik bon dan kartu yang mana.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_cash_advance_allocations')) {
            return;
        }

        Schema::create('bs_cash_advance_allocations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->default(1)->index();
            $t->foreignId('cash_advance_id')->constrained('bs_cash_advances')->cascadeOnDelete();
            /* Kartunya disalin ke sini, tidak hanya dibaca lewat bonnya. Kolom "Pemakaian" pada
               Set Up Credit Card dikelompokkan per kartu per bulan; menjoin lewat bon pada tiap
               pembacaan membuat angka kartu bergantung pada baris bon yang bisa ter-soft-delete. */
            $t->unsignedBigInteger('credit_card_id')->nullable()->index();
            $t->date('alloc_date')->index();
            $t->string('description', 255)->nullable();
            $t->decimal('amount', 20, 2)->default(0);
            // Bank/kas yang mengeluarkan uangnya — sisi KREDIT jurnalnya.
            $t->unsignedBigInteger('cash_account_id');
            $t->unsignedBigInteger('journal_id')->nullable()->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();

            $t->index(['credit_card_id', 'alloc_date'], 'bs_ca_alloc_card_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cash_advance_allocations');
    }
};
