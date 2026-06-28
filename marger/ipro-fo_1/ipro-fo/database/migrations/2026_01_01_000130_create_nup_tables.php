<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launchings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->constrained();
            $t->string('name');
            $t->datetime('launching_at');
            $t->json('rules')->nullable();
            $t->string('status', 32)->default('draft');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('nup_schemes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->constrained();
            $t->foreignId('launching_id')->nullable()->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->decimal('nup_amount', 20, 2);
            $t->date('open_from');
            $t->date('open_to');
            $t->boolean('is_refundable')->default(true);
            $t->decimal('refund_penalty_pct', 5, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('nup_registrations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('nup_scheme_id')->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->string('nup_number', 64)->unique();
            $t->unsignedInteger('queue_no');
            $t->date('registered_at');
            $t->foreignId('sales_person_id')->nullable()->constrained('sales_persons');
            $t->foreignId('agent_id')->nullable()->constrained();
            $t->string('status', 32)->default('active');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['nup_scheme_id', 'queue_no']);
        });

        Schema::create('nup_payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('nup_registration_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 20, 2);
            $t->date('paid_at');
            $t->string('payment_method', 32);
            $t->string('reference_no')->nullable();
            $t->foreignId('received_by')->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nup_payments');
        Schema::dropIfExists('nup_registrations');
        Schema::dropIfExists('nup_schemes');
        Schema::dropIfExists('launchings');
    }
};
