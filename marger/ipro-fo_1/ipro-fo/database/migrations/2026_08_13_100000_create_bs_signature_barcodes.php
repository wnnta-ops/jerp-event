<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barcode tanda tangan per pengguna.
 *
 * Dipakai modul SIP: begitu seorang pengguna menyetujui sebuah slot TTD, yang muncul adalah
 * barcode tanda tangannya, bukan namanya. Dikelola lewat Cash Book → Setup → Set Up Barcode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_signature_barcodes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Isi barcode — bebas huruf/angka/titik/strip (Code 128-B).
            $t->string('code', 64);
            // Keterangan singkat, mis. jabatan penanda tangan.
            $t->string('label', 120)->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            // Satu pengguna satu barcode per tenant, dan isi barcode tidak boleh kembar
            // supaya satu barcode tidak pernah mewakili dua orang.
            $t->unique(['tenant_id', 'user_id']);
            $t->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_signature_barcodes');
    }
};
