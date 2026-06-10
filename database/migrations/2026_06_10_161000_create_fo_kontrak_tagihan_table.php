<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal Tagihan per-Kontrak (editable) — baris tagihan BF/DP/ANG/ADM dengan
 * jatuh tempo, nilai, dan pelunasan. Awalnya bisa di-generate dari skema bayar,
 * lalu di-edit di tab "Jadwal Tagihan" pada edit kontrak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_kontrak_tagihan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kontrak_id')->constrained('fo_kontrak')->cascadeOnDelete();
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->enum('tipe', ['BF', 'DP', 'ANG', 'ADM', 'LAIN'])->default('ANG');
            $table->string('nama_tagihan');
            $table->date('jatuh_tempo')->nullable();
            $table->decimal('nilai', 18, 2)->default(0);
            $table->decimal('pelunasan', 18, 2)->default(0);
            $table->string('keterangan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_kontrak_tagihan');
    }
};
