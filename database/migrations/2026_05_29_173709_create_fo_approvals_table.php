<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_approval', 32);
            $table->date('tgl_approval');
            $table->enum('jenis', [
                'DISKON', 'BATAL_KONTRAK', 'ADJ_HARGA', 'RESCHEDULE',
                'GANTI_NAMA', 'GANTI_UNIT', 'REFUND', 'LAIN',
            ])->default('DISKON');
            $table->string('subject_type')->nullable();          // morph (Kontrak, Reservasi, ...)
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('alasan');
            $table->json('detail_lama')->nullable();             // snapshot before
            $table->json('detail_baru')->nullable();             // snapshot after / requested
            $table->decimal('amount_dampak', 18, 2)->nullable();
            $table->enum('status', ['DRAFT', 'PENDING', 'APPROVED', 'REJECTED', 'EXECUTED'])->default('DRAFT');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('catatan_approver')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_approval']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'jenis']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_approvals');
    }
};
