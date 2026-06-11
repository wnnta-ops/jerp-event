<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BankLoan (KPR) — schema diport dari API .NET 7 (103 migrasi EF Core) via referensi
 * eproperty. Kode dokumen KPR/RA/PR/RR verbatim. Status enum nilai Indonesia/legacy.
 * occupations & incomes = master pendukung data customer KPR.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Master pendukung customer KPR
        Schema::create('occupations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->string('name');            // range penghasilan, mis. "5-10 jt"
            $table->timestamps();
            $table->softDeletes();
        });

        // Master: checklist requirements & retention categories
        Schema::create('bankloan_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bankloan_bank_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('banks')->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained('bankloan_requirements')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['bank_id', 'requirement_id']);
        });

        Schema::create('bankloan_retention_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Customers (KPR-local; disync dari FO)
        Schema::create('bankloan_customers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('nik')->nullable();
            $table->string('birth_place')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('occupation_id')->nullable()->constrained('occupations')->nullOnDelete();
            $table->foreignId('income_id')->nullable()->constrained('incomes')->nullOnDelete();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        // Contracts (KPR-...)
        Schema::create('bankloan_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number')->unique();      // KPR-2025-00001
            $table->string('booking_number')->nullable();
            $table->date('booking_date')->nullable();
            $table->foreignId('customer_id')->constrained('bankloan_customers')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('unit_code')->nullable();           // natural key sync FO
            $table->string('sales_person_name')->nullable();
            $table->decimal('total_price', 19, 4)->default(0);
            $table->decimal('submission_amount', 19, 4)->default(0);
            $table->decimal('down_payment', 19, 4)->default(0);
            $table->string('active_status')->default('DRAFT');  // DRAFT/REGISTERED/ACTIVE/CLOSED/CANCELLED
            $table->string('buyback_status')->nullable();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['active_status', 'project_id'], 'ix_bankloan_contract_status_project');
        });

        Schema::create('bankloan_contract_pre_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('bankloan_contracts')->cascadeOnDelete();
            $table->string('fund_source')->nullable();
            $table->string('purchasing_purpose')->nullable();
            $table->timestamps();
        });

        Schema::create('bankloan_bi_checking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('bankloan_contracts')->cascadeOnDelete();
            $table->string('result')->nullable();
            $table->date('checked_at')->nullable();
            $table->date('due_date')->nullable();           // utk reminder job
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bankloan_bi_checking_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bi_checking_id')->constrained('bankloan_bi_checking')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_file_name')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
        });

        // Contract ↔ Bank (multi-bank per contract)
        Schema::create('bankloan_contract_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('bankloan_contracts')->cascadeOnDelete();
            $table->foreignId('bank_id')->constrained('banks')->cascadeOnDelete();
            $table->string('requirement_status')->nullable();
            $table->decimal('approved_amount', 19, 4)->nullable();
            $table->string('approved_description')->nullable();
            $table->string('status')->default('PENGAJUAN'); // PENGAJUAN/APPROVED/REJECTED/AKAD
            $table->timestamps();
            $table->softDeletes();

            $table->index('status', 'ix_bankloan_cb_status');
        });

        Schema::create('bankloan_contract_bank_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_bank_id')->constrained('bankloan_contract_banks')->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained('bankloan_requirements')->cascadeOnDelete();
            $table->date('filing_date')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_file_name')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('bankloan_contract_bank_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_bank_id')->constrained('bankloan_contract_banks')->cascadeOnDelete();
            $table->date('interview_date')->nullable();
            $table->string('result')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('bankloan_contract_bank_sp3k', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_bank_id')->constrained('bankloan_contract_banks')->cascadeOnDelete();
            $table->string('sp3k_number')->nullable();
            $table->date('sp3k_date')->nullable();
            $table->string('result')->nullable();
            $table->decimal('approved_amount', 19, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('bankloan_contract_bank_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_bank_id')->constrained('bankloan_contract_banks')->cascadeOnDelete();
            $table->string('agreement_number')->nullable();
            $table->date('agreement_date')->nullable();
            $table->string('notary')->nullable();
            $table->string('disbursement_type')->nullable();
            $table->timestamps();
        });

        Schema::create('bankloan_contract_bank_retentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_bank_id')->constrained('bankloan_contract_banks')->cascadeOnDelete();
            $table->foreignId('retention_category_id')->nullable()->constrained('bankloan_retention_categories')->nullOnDelete();
            $table->decimal('amount_held', 19, 4)->default(0);
            $table->date('scheduled_release_date')->nullable();
            $table->timestamps();
        });

        // RA — Agreement Realization (pencairan akad)
        Schema::create('bankloan_agreement_realizations', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();   // RA-...
            $table->foreignId('contract_bank_agreement_id')->constrained('bankloan_contract_bank_agreements', 'id', 'ba_real_cba_fk')->cascadeOnDelete();
            $table->date('realization_date')->nullable();
            $table->decimal('disbursed_amount', 19, 4)->default(0);
            $table->decimal('excess_realization_amount', 19, 4)->default(0);
            $table->string('status')->default('DRAFT');
            $table->timestamps();
            $table->softDeletes();
        });

        // PR — Retention Submission (pengajuan retensi)
        Schema::create('bankloan_retention_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();   // PR-...
            $table->foreignId('contract_bank_retention_id')->constrained('bankloan_contract_bank_retentions', 'id', 'ret_sub_cbr_fk')->cascadeOnDelete();
            $table->date('submission_date')->nullable();
            $table->decimal('requested_amount', 19, 4)->default(0);
            $table->string('sales_person_name')->nullable();
            $table->string('contract_number')->nullable();  // natural key
            $table->string('status')->default('DRAFT');
            $table->timestamps();
            $table->softDeletes();
        });

        // RR — Retention Realization (realisasi retensi)
        Schema::create('bankloan_retention_realizations', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();   // RR-...
            $table->foreignId('retention_submission_id')->constrained('bankloan_retention_submissions', 'id', 'ret_real_rs_fk')->cascadeOnDelete();
            $table->date('realization_date')->nullable();
            $table->decimal('realized_amount', 19, 4)->default(0);
            $table->decimal('remaining_amount', 19, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bankloan_contract_bank_retention_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_bank_retention_id')->constrained('bankloan_contract_bank_retentions', 'id', 'cbrs_cbr_fk')->cascadeOnDelete();
            $table->foreignId('retention_submission_id')->constrained('bankloan_retention_submissions', 'id', 'cbrs_rs_fk')->cascadeOnDelete();
            $table->timestamps();
        });

        // Minutes of Meeting (developer ↔ bank)
        Schema::create('bankloan_minutes_of_meeting', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->date('meeting_date')->nullable();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Change log keyed by natural code (audit)
        Schema::create('bankloan_change_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->string('reference_code')->nullable();
            $table->string('reference_name')->nullable();
            $table->string('reference_number')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach ([
            'bankloan_change_logs',
            'bankloan_minutes_of_meeting',
            'bankloan_contract_bank_retention_submissions',
            'bankloan_retention_realizations',
            'bankloan_retention_submissions',
            'bankloan_agreement_realizations',
            'bankloan_contract_bank_retentions',
            'bankloan_contract_bank_agreements',
            'bankloan_contract_bank_sp3k',
            'bankloan_contract_bank_interviews',
            'bankloan_contract_bank_requirements',
            'bankloan_contract_banks',
            'bankloan_bi_checking_files',
            'bankloan_bi_checking',
            'bankloan_contract_pre_interviews',
            'bankloan_contracts',
            'bankloan_customers',
            'bankloan_retention_categories',
            'bankloan_bank_requirements',
            'bankloan_requirements',
            'incomes',
            'occupations',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
