<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppjb_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('contract_id')->constrained();
            $t->string('ppjb_number', 64)->unique();
            $t->date('signed_at');
            $t->string('file_path')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
        });

        Schema::create('ajb_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('booking_id')->constrained();
            $t->string('ajb_number', 64)->unique();
            $t->date('signed_at');
            $t->string('notary_name')->nullable();
            $t->string('file_path')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
        });

        Schema::create('bast_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('booking_id')->constrained();
            $t->string('bast_number', 64)->unique();
            $t->date('handed_over_at');
            $t->json('checklist')->nullable();
            $t->string('file_path')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
        });

        Schema::create('imb_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('unit_id')->constrained();
            $t->string('imb_number', 64);
            $t->date('issued_at');
            $t->string('file_path')->nullable();
            $t->timestamps();
        });

        Schema::create('sertifikat_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('unit_id')->constrained();
            $t->foreignId('owner_customer_id')->nullable()->constrained('customers');
            $t->enum('type', ['SHM', 'HGB', 'HGU', 'HPL']);
            $t->string('serial_number', 64);
            $t->date('issued_at');
            $t->string('file_path')->nullable();
            $t->timestamps();
        });

        Schema::create('commission_schemes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->nullable()->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('commission_scheme_terms', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('commission_scheme_id')->constrained()->cascadeOnDelete();
            $t->string('agent_type', 16);
            $t->string('trigger_event', 32);
            $t->decimal('rate_pct', 5, 2)->nullable();
            $t->decimal('flat_amount', 20, 2)->nullable();
            $t->unsignedTinyInteger('term_no')->default(1);
            $t->timestamps();
        });

        Schema::create('commissions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('booking_id')->constrained();
            $t->foreignId('commission_scheme_id')->nullable()->constrained();
            $t->decimal('base_amount', 20, 2);
            $t->string('status', 32)->default('scheduled');
            $t->timestamps();
        });

        Schema::create('commission_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('commission_id')->constrained()->cascadeOnDelete();
            $t->foreignId('agent_id')->constrained();
            $t->string('tier', 16);
            $t->decimal('rate_pct', 5, 2)->nullable();
            $t->decimal('amount', 20, 2);
            $t->string('trigger_event', 32);
            $t->string('status', 32)->default('scheduled');
            $t->timestamps();
            $t->index(['commission_id', 'tier']);
        });

        Schema::create('commission_payouts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('commission_line_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->date('paid_at')->nullable();
            $t->foreignId('memo_id')->nullable()->constrained();
            $t->string('status', 32)->default('ready');
            $t->unsignedBigInteger('approval_id')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payouts');
        Schema::dropIfExists('commission_lines');
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_scheme_terms');
        Schema::dropIfExists('commission_schemes');
        Schema::dropIfExists('sertifikat_documents');
        Schema::dropIfExists('imb_documents');
        Schema::dropIfExists('bast_documents');
        Schema::dropIfExists('ajb_documents');
        Schema::dropIfExists('ppjb_documents');
    }
};
