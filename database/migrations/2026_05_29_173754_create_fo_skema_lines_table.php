<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_skema_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skema_id')->constrained('fo_skema')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedSmallInteger('urutan');
            $table->string('nama_termin');                       // BF, DP1, DP2, ANGS1, ...
            $table->enum('basis', ['PERSEN', 'NOMINAL'])->default('PERSEN');
            $table->decimal('persen', 5, 2)->nullable();
            $table->decimal('nominal', 18, 2)->nullable();
            $table->unsignedSmallInteger('jatuh_tempo_hari')->nullable(); // T+N from kontrak date
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->unique(['skema_id', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_skema_lines');
    }
};
