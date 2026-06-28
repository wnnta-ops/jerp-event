<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_products', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('bank_partner_id')->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->decimal('interest_pct', 5, 2)->nullable();
            $t->unsignedSmallInteger('max_tenor_month');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('kpa_applications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('application_number', 64)->unique();
            $t->foreignId('booking_id')->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('bank_partner_id')->constrained();
            $t->foreignId('bank_product_id')->nullable()->constrained();
            $t->decimal('plafon_requested', 20, 2);
            $t->decimal('plafon_approved', 20, 2)->nullable();
            $t->unsignedSmallInteger('tenor_month')->nullable();
            $t->date('submitted_at');
            $t->date('reviewed_at')->nullable();
            $t->string('status', 32)->default('draft');
            $t->text('reject_reason')->nullable();
            $t->foreignId('handled_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('kpa_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('kpa_application_id')->constrained()->cascadeOnDelete();
            $t->string('type', 32);
            $t->string('file_path');
            $t->string('status', 16)->default('pending');
            $t->timestamps();
        });

        Schema::create('kpa_sp3k', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('kpa_application_id')->constrained();
            $t->string('sp3k_number', 64);
            $t->date('sp3k_date');
            $t->decimal('approved_amount', 20, 2);
            $t->decimal('interest_pct', 5, 2)->nullable();
            $t->unsignedSmallInteger('tenor_month')->nullable();
            $t->string('status', 16)->default('issued');
            $t->date('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('kpa_akads', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('kpa_application_id')->constrained();
            $t->datetime('akad_at');
            $t->string('place')->nullable();
            $t->string('notary')->nullable();
            $t->string('status', 16)->default('scheduled');
            $t->timestamps();
        });

        Schema::create('kpa_realizations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('kpa_application_id')->constrained();
            $t->date('disbursed_at');
            $t->decimal('disbursed_amount', 20, 2);
            $t->decimal('retention_amount', 20, 2)->default(0);
            $t->string('reference_no')->nullable();
            $t->foreignId('bank_account_id')->nullable()->constrained();
            $t->timestamps();
        });

        Schema::create('kpa_retentions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('kpa_realization_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->date('released_at')->nullable();
            $t->string('status', 16)->default('held');
            $t->text('claim_reason')->nullable();
            $t->timestamps();
        });

        Schema::create('kpa_buybacks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('booking_id')->constrained();
            $t->date('initiated_at');
            $t->decimal('refund_amount', 20, 2);
            $t->decimal('penalty_amount', 20, 2)->default(0);
            $t->string('status', 32)->default('pending');
            $t->unsignedBigInteger('approval_id')->nullable();
            $t->date('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpa_buybacks');
        Schema::dropIfExists('kpa_retentions');
        Schema::dropIfExists('kpa_realizations');
        Schema::dropIfExists('kpa_akads');
        Schema::dropIfExists('kpa_sp3k');
        Schema::dropIfExists('kpa_documents');
        Schema::dropIfExists('kpa_applications');
        Schema::dropIfExists('bank_products');
    }
};
