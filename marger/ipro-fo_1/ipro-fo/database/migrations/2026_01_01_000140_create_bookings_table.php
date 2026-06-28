<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_holds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('unit_id')->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('sales_person_id')->nullable()->constrained('sales_persons');
            $t->datetime('held_at')->useCurrent();
            $t->datetime('expires_at');
            $t->string('reason', 128)->nullable();
            $t->string('status', 32)->default('active');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['unit_id', 'status']);
            $t->index('expires_at');
        });

        Schema::create('bookings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->constrained();
            $t->string('booking_number', 64)->unique();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('unit_id')->constrained();
            $t->foreignId('payment_scheme_id')->constrained();
            $t->foreignId('sales_person_id')->nullable()->constrained('sales_persons');
            $t->foreignId('agent_id')->nullable()->constrained();
            $t->foreignId('nup_registration_id')->nullable()->constrained();
            $t->date('booking_date');
            $t->decimal('base_price', 20, 2);
            $t->decimal('discount_amount', 20, 2)->default(0);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('gimmick_value', 20, 2)->default(0);
            $t->decimal('ppn_pct', 5, 2)->default(11);
            $t->decimal('ppn_amount', 20, 2)->default(0);
            $t->decimal('total_price', 20, 2);
            $t->string('booking_source', 32)->default('direct');
            $t->string('status', 32)->default('draft');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->softDeletes();
            $t->index('customer_id');
            $t->index('unit_id');
            $t->index('sales_person_id');
            $t->index(['status', 'booking_date']);
        });

        Schema::create('booking_discounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->string('reason', 128);
            $t->enum('basis', ['percent', 'amount']);
            $t->decimal('value', 20, 2);
            $t->unsignedBigInteger('approval_id')->nullable();
            $t->timestamps();
        });

        Schema::create('payment_schedules', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('booking_id')->constrained();
            $t->unsignedSmallInteger('installment_no');
            $t->string('label')->nullable();
            $t->date('due_date');
            $t->decimal('amount', 20, 2);
            $t->decimal('paid_amount', 20, 2)->default(0);
            $t->string('status', 32)->default('outstanding');
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['booking_id', 'installment_no']);
            $t->index(['due_date', 'status']);
        });

        Schema::create('booking_status_histories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32);
            $t->foreignId('changed_by')->constrained('users');
            $t->text('notes')->nullable();
            $t->timestamp('changed_at')->useCurrent();
            $t->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_histories');
        Schema::dropIfExists('payment_schedules');
        Schema::dropIfExists('booking_discounts');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('unit_holds');
    }
};
