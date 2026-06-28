<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_persons', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->foreignId('manager_id')->nullable()->constrained('sales_persons');
            $t->date('joined_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('agent_types', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 16);
            $t->string('label');
            $t->timestamps();
            $t->unique('code');
        });

        Schema::create('agents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->foreignId('agent_type_id')->constrained();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('npwp', 255)->nullable();
            $t->string('bank_name', 64)->nullable();
            $t->string('bank_account_no', 255)->nullable();
            $t->string('bank_account_name', 128)->nullable();
            $t->foreignId('parent_agent_id')->nullable()->constrained('agents');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('payment_schemes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->nullable()->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->enum('type', ['cash_keras', 'cicilan', 'kpr', 'kpa', 'sewa_beli', 'mixed']);
            $t->decimal('down_payment_pct', 5, 2)->default(0);
            $t->unsignedSmallInteger('installment_months')->default(0);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'project_id', 'code']);
        });

        Schema::create('payment_scheme_terms', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_scheme_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('term_no');
            $t->string('label');
            $t->enum('basis', ['percent', 'amount']);
            $t->decimal('value', 20, 2);
            $t->unsignedSmallInteger('due_after_days')->default(0);
            $t->boolean('is_dp')->default(false);
            $t->timestamps();
            $t->unique(['payment_scheme_id', 'term_no']);
        });

        Schema::create('gimmicks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->nullable()->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->text('description')->nullable();
            $t->decimal('value', 20, 2)->default(0);
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bank_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('company_id')->nullable()->constrained();
            $t->string('account_name');
            $t->string('account_number', 64);
            $t->string('bank_name', 64);
            $t->string('branch')->nullable();
            $t->boolean('is_virtual_pool')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::table('customers', function (Blueprint $t): void {
            $t->foreign('referrer_agent_id')->references('id')->on('agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t): void {
            $t->dropForeign(['referrer_agent_id']);
        });
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('gimmicks');
        Schema::dropIfExists('payment_scheme_terms');
        Schema::dropIfExists('payment_schemes');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('agent_types');
        Schema::dropIfExists('sales_persons');
    }
};
