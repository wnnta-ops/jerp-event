<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setup Pilih Unit per cluster: giliran HP (HP 1 → … → HP n → HP 1) dan batas waktu memilih.
 * Cluster tanpa baris di sini berjalan seperti sebelumnya: tanpa giliran, waktu 2 menit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_pilih_unit_setup', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('lokasi_id')->unique()->constrained('fo_lokasi')->cascadeOnDelete();
            $table->boolean('giliran_aktif')->default(false);
            $table->unsignedSmallInteger('jumlah_hp')->default(5);
            $table->unsignedSmallInteger('giliran_hp')->default(1); // HP yang sedang mendapat giliran
            $table->boolean('timer_aktif')->default(true);
            $table->unsignedSmallInteger('timer_menit')->default(2);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_pilih_unit_setup');
    }
};
