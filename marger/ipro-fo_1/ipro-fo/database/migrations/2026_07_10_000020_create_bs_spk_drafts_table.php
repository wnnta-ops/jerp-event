<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set Up SPK — draft/template default untuk dokumen Cetak SPK.
 * Satu baris per tenant; menyimpan judul, tempat, pembukaan, dan daftar pasal
 * default (title + body, mendukung placeholder {…}). Cetak SPK memuat draft ini
 * dan mengganti placeholder; bila kosong, fallback ke default bawaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_spk_drafts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->unique()->constrained();
            $t->string('judul', 120)->default('SURAT PERINTAH KERJA');
            $t->string('subjudul', 60)->default('(SPK)');
            $t->string('tempat', 80)->nullable();
            $t->text('pembukaan')->nullable();
            $t->json('pasals')->nullable();     // [{title, body}] — body boleh mengandung {placeholder}
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_spk_drafts');
    }
};
