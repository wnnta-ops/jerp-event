<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->nullable()->constrained();
            $t->string('tts_number', 64)->unique();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('booking_id')->nullable()->constrained();
            $t->decimal('amount', 20, 2);
            $t->string('payment_method', 32);
            $t->date('received_date');
            $t->foreignId('bank_account_id')->nullable()->constrained();
            $t->string('reference_no')->nullable();
            $t->string('status', 32)->default('pending');
            $t->text('notes')->nullable();
            $t->foreignId('received_by')->constrained('users');
            $t->foreignId('posted_by')->nullable()->constrained('users');
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('customer_id');
            $t->index(['payment_method', 'status']);
            $t->index('received_date');
        });

        Schema::create('tts_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tts_receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payment_schedule_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->timestamps();
            $t->unique(['tts_receipt_id', 'payment_schedule_id']);
        });

        Schema::create('tts_giros', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tts_receipt_id')->constrained()->cascadeOnDelete();
            $t->string('giro_number');
            $t->string('issuing_bank');
            $t->date('giro_date');
            $t->date('due_date');
            $t->string('status', 32)->default('open');
            $t->date('cleared_at')->nullable();
            $t->date('rejected_at')->nullable();
            $t->string('reject_reason')->nullable();
            $t->foreignId('replaced_by')->nullable()->constrained('tts_giros');
            $t->timestamps();
        });

        Schema::create('memos', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('memo_number', 64)->unique();
            $t->enum('type', ['incoming', 'outgoing']);
            $t->date('memo_date');
            $t->decimal('amount', 20, 2);
            $t->string('reference_type')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->text('description');
            $t->foreignId('bank_account_id')->nullable()->constrained();
            $t->string('status', 32)->default('draft');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['reference_type', 'reference_id']);
        });

        Schema::create('kuitansis', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('kuitansi_number', 64)->unique();
            $t->foreignId('tts_receipt_id')->nullable()->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->date('issued_date');
            $t->decimal('amount', 20, 2);
            $t->text('terbilang');
            $t->string('signed_by')->nullable();
            $t->string('status', 32)->default('issued');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('kuitansi_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('kuitansi_id')->constrained()->cascadeOnDelete();
            $t->string('description');
            $t->decimal('amount', 20, 2);
            $t->timestamps();
        });

        Schema::create('cashbacks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('booking_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->string('reason')->nullable();
            $t->string('status', 32)->default('pending');
            $t->unsignedBigInteger('approval_id')->nullable();
            $t->date('paid_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashbacks');
        Schema::dropIfExists('kuitansi_items');
        Schema::dropIfExists('kuitansis');
        Schema::dropIfExists('memos');
        Schema::dropIfExists('tts_giros');
        Schema::dropIfExists('tts_allocations');
        Schema::dropIfExists('tts_receipts');
    }
};
