<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekonsiliasi harian eProperty ↔ J-ERP (`php artisan eprop:sinkron`).
 *
 *  - eprop_tautan : "kartu identitas" — dokumen eProperty (tabel + nomor) ↔ baris J-ERP-nya,
 *                   entah hasil disalin (masuk), dicocokkan dengan input J-ERP (cocok), atau
 *                   diperbarui. Dokumen yang sudah bertaut tidak diproses ulang sebagai baru.
 *  - eprop_jalan  : satu baris per kali sinkron (kiriman tanggal berapa, ringkasan angka).
 *  - eprop_hasil  : rincian per dokumen tiap jalan — dibaca laporan & layar Rekonsiliasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eprop_tautan', function (Blueprint $t) {
            $t->id();
            $t->string('tabel', 64);
            $t->string('kunci', 191);              // nomor dokumen eProperty (kunci alami)
            $t->unsignedBigInteger('id_jerp')->nullable();
            $t->string('status', 16);              // masuk / cocok / diperbarui
            $t->timestamp('disinkron_pada')->nullable();
            $t->timestamps();
            $t->unique(['tabel', 'kunci']);
            $t->index(['tabel', 'id_jerp']);
        });

        Schema::create('eprop_jalan', function (Blueprint $t) {
            $t->id();
            $t->string('kiriman', 32)->nullable();  // mis. 2026-10-20
            $t->boolean('kering')->default(false);  // uji coba tanpa menulis
            $t->string('status', 16)->default('berjalan');
            $t->json('ringkasan')->nullable();
            $t->text('galat')->nullable();
            $t->timestamp('mulai')->nullable();
            $t->timestamp('selesai')->nullable();
            $t->timestamps();
        });

        Schema::create('eprop_hasil', function (Blueprint $t) {
            $t->id();
            $t->foreignId('jalan_id');
            $t->string('modul', 48);
            $t->string('tabel', 64);
            $t->string('kunci', 191);
            $t->string('aksi', 16);                // masuk / cocok / ditahan / diperbarui / konflik / hilang
            $t->string('ringkas', 500)->nullable(); // nama/keterangan · nominal · tanggal (sisi eProperty)
            $t->string('jerp', 500)->nullable();    // pasangan / kandidat di J-ERP
            $t->string('alasan', 500)->nullable();
            $t->decimal('nominal', 20, 2)->nullable();
            $t->date('tanggal')->nullable();
            $t->timestamps();
            $t->index(['jalan_id', 'aksi']);
            $t->index(['tabel', 'kunci']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eprop_hasil');
        Schema::dropIfExists('eprop_jalan');
        Schema::dropIfExists('eprop_tautan');
    }
};
