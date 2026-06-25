<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surat Peringatan (SP) tercetak — penerbitan surat peringatan keterlambatan
 * pembayaran (UangMuka/Angsuran) ke customer. Berbeda dari fo_collection_notices
 * (riwayat kirim Email/WA): ini SURAT TERCETAK dgn nomor resmi, level (SP1/2/3/
 * Pembatalan), pilihan baris tunggakan + denda manual, snapshot identitas. Dipakai
 * menu "Surat SP" (cetak PDF sesuai FORMAT SURAT SP.pdf).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_sp_letters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('kontrak_id')->constrained('fo_kontrak')->cascadeOnDelete();
            $table->string('no_surat')->nullable();
            $table->string('level', 16);                 // SP1 | SP2 | SP3 | PEMBATALAN
            $table->date('tgl_surat');
            $table->date('jatuh_tempo')->nullable();     // tgl_surat + tenggat (default 7 hari)

            // Snapshot identitas penerima (boleh diedit saat terbit).
            $table->string('nama')->nullable();
            $table->text('alamat')->nullable();
            $table->string('telp', 64)->nullable();
            $table->string('ref_spr')->nullable();       // No. SPR + tgl (poin 1 surat)

            // Baris tunggakan terpilih (snapshot): [{tagihan_id,tgl,keterangan,
            // tagihan,pembayaran,kekurangan,hari,denda}, ...]
            $table->json('items')->nullable();
            $table->decimal('total_tagihan', 18, 2)->default(0);
            $table->decimal('total_pembayaran', 18, 2)->default(0);
            $table->decimal('total_kekurangan', 18, 2)->default(0);
            $table->decimal('total_denda', 18, 2)->default(0);

            // Penandatangan (snapshot dari setting saat terbit).
            $table->string('penandatangan_nama')->nullable();
            $table->string('penandatangan_jabatan')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['kontrak_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_sp_letters');
    }
};
