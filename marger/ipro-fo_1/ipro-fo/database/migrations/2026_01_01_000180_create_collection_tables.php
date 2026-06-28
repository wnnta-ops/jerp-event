<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('followups', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('sales_person_id')->nullable()->constrained('sales_persons');
            $t->string('channel', 32);
            $t->datetime('contacted_at');
            $t->string('result', 64);
            $t->text('notes');
            $t->datetime('next_action_at')->nullable();
            $t->string('status', 32)->default('open');
            $t->timestamps();
            $t->index(['customer_id', 'contacted_at']);
            $t->index('next_action_at');
        });

        Schema::create('dendas', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_schedule_id')->constrained();
            $t->date('computed_date');
            $t->unsignedInteger('overdue_days');
            $t->decimal('rate_per_day', 6, 4)->default(0.0005);
            $t->decimal('amount', 20, 2);
            $t->decimal('amount_waived', 20, 2)->default(0);
            $t->decimal('amount_paid', 20, 2)->default(0);
            $t->string('status', 32)->default('open');
            $t->timestamps();
            $t->index(['payment_schedule_id', 'computed_date']);
        });

        Schema::create('pjt_headers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('pjt_number', 64)->unique();
            $t->foreignId('customer_id')->constrained();
            $t->date('period_at');
            $t->string('transaction_type', 16);
            $t->decimal('total_overdue', 20, 2);
            $t->string('status', 32)->default('open');
            $t->timestamps();
        });

        Schema::create('pjt_details', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('pjt_header_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payment_schedule_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->timestamps();
        });

        Schema::create('tunggakan_headers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->string('transaction_type', 16);
            $t->string('category', 16);
            $t->unsignedSmallInteger('term_overdue_count');
            $t->decimal('total_amount', 20, 2);
            $t->string('status', 32)->default('open');
            $t->date('first_overdue_at');
            $t->timestamps();
            $t->unique(['customer_id', 'transaction_type']);
        });

        Schema::create('tunggakan_details', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tunggakan_header_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payment_schedule_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->timestamps();
        });

        Schema::create('surat_peringatans', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('sp_number', 64)->unique();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('tunggakan_header_id')->nullable()->constrained();
            $t->enum('level', ['SP1', 'SP2', 'SP3']);
            $t->date('issued_at');
            $t->date('deadline_at');
            $t->string('status', 32)->default('issued');
            $t->foreignId('signed_by')->constrained('users');
            $t->timestamps();
        });

        Schema::create('skl_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('skl_number', 64)->unique();
            $t->foreignId('booking_id')->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->date('issued_at');
            $t->foreignId('signed_by')->constrained('users');
            $t->string('status', 32)->default('draft');
            $t->unsignedBigInteger('approval_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skl_documents');
        Schema::dropIfExists('surat_peringatans');
        Schema::dropIfExists('tunggakan_details');
        Schema::dropIfExists('tunggakan_headers');
        Schema::dropIfExists('pjt_details');
        Schema::dropIfExists('pjt_headers');
        Schema::dropIfExists('dendas');
        Schema::dropIfExists('followups');
    }
};
