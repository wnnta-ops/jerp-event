<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detail Skema Bayar PER-KONTRAK (termin) — bisa diedit/diinput sendiri untuk
 * case khusus, mirror struktur fo_skema_lines. Dari termin ini Jadwal Tagihan
 * digenerate. Awalnya bisa disalin dari master Skema, lalu disesuaikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_kontrak_skema_line', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kontrak_id')->constrained('fo_kontrak')->cascadeOnDelete();
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->enum('tipe', ['BF', 'DP', 'ANG', 'ADM'])->default('ANG');
            $table->string('nama_termin');
            $table->enum('basis', ['PERSEN', 'NOMINAL'])->default('PERSEN');
            $table->decimal('persen', 8, 4)->default(0);
            $table->decimal('nominal', 18, 2)->default(0);
            $table->enum('jadwal_basis', ['M', 'D'])->default('D');   // M=Bulan, D=Hari
            $table->integer('jadwal_nilai')->default(0);
            $table->integer('setelah')->default(0);
            $table->boolean('flag_bf')->default(false);
            $table->boolean('flag_kpr')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_kontrak_skema_line');
    }
};
