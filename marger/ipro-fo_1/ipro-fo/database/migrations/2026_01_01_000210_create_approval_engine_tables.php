<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_chains', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->nullable()->constrained();
            $t->string('document_type', 64);
            $t->string('code', 32);
            $t->string('name');
            $t->json('match_conditions')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'project_id', 'document_type', 'code']);
        });

        Schema::create('approval_chain_steps', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('approval_chain_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('level');
            $t->string('approver_type', 32);
            $t->string('approver_value');
            $t->json('threshold')->nullable();
            $t->boolean('is_final')->default(false);
            $t->timestamps();
            $t->unique(['approval_chain_id', 'level']);
        });

        Schema::create('approvals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('approval_chain_id')->constrained();
            $t->string('document_type', 64);
            $t->string('approvable_type');
            $t->unsignedBigInteger('approvable_id');
            $t->json('payload')->nullable();
            $t->string('status', 32)->default('pending');
            $t->foreignId('submitted_by')->constrained('users');
            $t->datetime('submitted_at')->useCurrent();
            $t->datetime('completed_at')->nullable();
            $t->timestamps();
            $t->index(['approvable_type', 'approvable_id']);
        });

        Schema::create('approval_steps', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('approval_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('level');
            $t->foreignId('approver_id')->nullable()->constrained('users');
            $t->string('status', 32)->default('pending');
            $t->text('notes')->nullable();
            $t->datetime('acted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('approval_actions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('approval_step_id')->constrained();
            $t->string('action', 32);
            $t->foreignId('actor_id')->constrained('users');
            $t->text('notes')->nullable();
            $t->datetime('acted_at')->useCurrent();
            $t->timestamps();
        });

        Schema::create('cancellation_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('booking_id')->constrained();
            $t->date('requested_at');
            $t->text('reason');
            $t->decimal('paid_to_date', 20, 2)->default(0);
            $t->decimal('penalty_amount', 20, 2)->default(0);
            $t->decimal('refund_amount', 20, 2)->default(0);
            $t->unsignedBigInteger('approval_id')->nullable();
            $t->string('status', 32)->default('pending');
            $t->timestamps();
        });

        Schema::create('name_change_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('booking_id')->constrained();
            $t->foreignId('old_customer_id')->constrained('customers');
            $t->foreignId('new_customer_id')->constrained('customers');
            $t->date('requested_at');
            $t->decimal('admin_fee', 20, 2)->default(0);
            $t->unsignedBigInteger('approval_id')->nullable();
            $t->string('status', 32)->default('pending');
            $t->timestamps();
        });

        Schema::create('unit_change_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('booking_id')->constrained();
            $t->foreignId('old_unit_id')->constrained('units');
            $t->foreignId('new_unit_id')->constrained('units');
            $t->decimal('price_diff', 20, 2);
            $t->decimal('admin_fee', 20, 2)->default(0);
            $t->unsignedBigInteger('approval_id')->nullable();
            $t->string('status', 32)->default('pending');
            $t->timestamps();
        });

        Schema::create('reschedule_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('booking_id')->constrained();
            $t->json('proposed_schedule');
            $t->decimal('admin_fee', 20, 2)->default(0);
            $t->unsignedBigInteger('approval_id')->nullable();
            $t->string('status', 32)->default('pending');
            $t->timestamps();
        });

        Schema::create('refunds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('refund_number', 64)->unique();
            $t->string('refundable_type');
            $t->unsignedBigInteger('refundable_id');
            $t->decimal('amount', 20, 2);
            $t->date('requested_at');
            $t->date('paid_at')->nullable();
            $t->foreignId('memo_id')->nullable()->constrained();
            $t->unsignedBigInteger('approval_id')->nullable();
            $t->string('status', 32)->default('pending');
            $t->timestamps();
            $t->index(['refundable_type', 'refundable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('reschedule_requests');
        Schema::dropIfExists('unit_change_requests');
        Schema::dropIfExists('name_change_requests');
        Schema::dropIfExists('cancellation_requests');
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('approval_chain_steps');
        Schema::dropIfExists('approval_chains');
    }
};
