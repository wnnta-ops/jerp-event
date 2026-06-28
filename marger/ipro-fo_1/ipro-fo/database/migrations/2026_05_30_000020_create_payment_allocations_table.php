<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Polymorphic Payment Allocation table.
 *
 * Setiap entry adalah satu "potong pembayaran" yang clear/partial-clear satu schedule.
 * Source bisa: TTS Receipt (cash), Memo (Free DP/Subsidi), KPA Disbursement, Manual.
 *
 * Schedule yang terisi penuh dari berbagai allocation = `paid` status.
 *
 * Note: existing `tts_allocations` tetap dipertahankan untuk backward compat.
 * `payment_allocations` adalah generic layer baru yang co-exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_schedule_id')->constrained()->cascadeOnDelete();

            // Polymorphic source — pakai morph map terdaftar di AppServiceProvider
            $table->string('source_type', 50);       // 'tts', 'memo', 'kpr_disbursement', 'manual'
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('payment_method', 5);     // TN/KD/KK/TR/BG/DN/KR
            $table->decimal('amount', 18, 2);
            $table->date('allocated_at');
            $table->string('reference_number', 100)->nullable(); // e.g. nomor TTS, nomor memo
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_type', 'source_id']);
            $table->index('payment_schedule_id');
            $table->index('payment_method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
