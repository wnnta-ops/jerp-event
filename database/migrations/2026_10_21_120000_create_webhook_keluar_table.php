<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antrean & catatan webhook KELUAR (J-ERP → aplikasi lain). Pertama dipakai Webhook Survei:
 * tiap perubahan SPR (dibuat/diubah/batal/hapus) dikirim ke aplikasi survei kepuasan customer.
 *
 * Setiap notifikasi DIcatat — terkirim, ditolak, atau masih menunggu — beserta jawaban server
 * tujuan, supaya ada bukti bila pihak penerima bertanya, dan yang gagal bisa dikirim ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_keluar', function (Blueprint $t): void {
            $t->id();
            $t->string('tujuan', 30)->default('survei')->index();
            $t->uuid('uuid')->unique();                  // `id` di body — SAMA pada tiap kirim ulang
            $t->string('event', 40);
            $t->string('kunci', 120)->nullable()->index(); // No. SPR
            $t->longText('body');                        // JSON persis yang ditandatangani & dikirim
            $t->timestamp('terjadi_pada')->nullable();   // occurred_at
            $t->string('status', 12)->default('antre')->index(); // antre | terkirim | ditolak | gagal
            $t->unsignedSmallInteger('percobaan')->default(0);
            $t->unsignedSmallInteger('kode_http')->nullable();
            $t->text('jawaban')->nullable();
            $t->timestamp('terkirim_pada')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_keluar');
    }
};
