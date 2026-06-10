<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gimmick per-Kontrak (free items/promo yang melekat ke satu kontrak), beda
 * dari master fo_gimmicks. Dipakai di tab "Gimmick" pada edit kontrak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_kontrak_gimmick', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kontrak_id')->constrained('fo_kontrak')->cascadeOnDelete();
            $table->foreignId('gimmick_id')->nullable()->constrained('fo_gimmicks')->nullOnDelete();
            $table->string('nama_barang');
            $table->string('satuan', 32)->nullable();   // VOUCHER, SET, UNIT, dst
            $table->boolean('diberikan')->default(true);
            $table->boolean('diterima')->default(false);
            $table->date('tgl_diterima')->nullable();
            $table->integer('qty')->default(0);
            $table->decimal('harga_satuan', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->string('keterangan')->nullable();
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_kontrak_gimmick');
    }
};
