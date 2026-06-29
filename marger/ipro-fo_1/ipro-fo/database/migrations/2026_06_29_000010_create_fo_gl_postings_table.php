<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel jembatan idempotensi posting jurnal FO → GL (dipakai J-ERP IproGlPoster).
 * Unik (tenant_id, reference) memberi idempotensi tingkat-DB yang race-safe TANPA
 * menyentuh constraint bs_journals (yang juga dipakai jurnal asli ipro-fo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_gl_postings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('reference');
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_gl_postings');
    }
};
