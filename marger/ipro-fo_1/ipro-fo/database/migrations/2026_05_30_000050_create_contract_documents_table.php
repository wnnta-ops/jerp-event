<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Contract Documents Checklist (Kelengkapan Berkas).
 *
 * Setiap kontrak punya beberapa entry — satu per document type.
 * is_present = true/false (toggle untuk "Tidak Ada / Ada").
 * file_path optional kalau ada upload scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 30);   // kk, ktp, npwp, akta_nikah, surat_kuasa, ppjb, lainnya
            $table->boolean('is_present')->default(false);
            $table->string('file_path', 255)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['contract_id', 'document_type']);
            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_documents');
    }
};
