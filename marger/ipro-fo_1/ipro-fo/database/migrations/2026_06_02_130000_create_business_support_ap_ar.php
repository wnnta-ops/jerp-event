<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUSINESS SUPPORT — Account Payable (Hutang) & Account Receivable (Piutang).
 * Invoice register + pelunasan, dengan jurnal GL otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_ap_invoices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('invoice_number', 48)->unique();
            $t->date('invoice_date');
            $t->date('due_date')->nullable();
            $t->unsignedBigInteger('supplier_id');
            $t->unsignedBigInteger('expense_account_id');   // akun biaya/aktiva (debit)
            $t->unsignedBigInteger('payable_account_id');   // akun hutang (kredit)
            $t->string('description');
            $t->decimal('dpp', 20, 2)->default(0);
            $t->decimal('ppn_pct', 5, 2)->default(0);
            $t->decimal('ppn_amount', 20, 2)->default(0);
            $t->decimal('total', 20, 2)->default(0);
            $t->decimal('paid_amount', 20, 2)->default(0);
            $t->string('status', 12)->default('open');      // open / paid
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_ap_payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ap_invoice_id')->constrained('bs_ap_invoices')->cascadeOnDelete();
            $t->date('payment_date');
            $t->unsignedBigInteger('cash_account_id');
            $t->decimal('amount', 20, 2);
            $t->string('reference')->nullable();
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('bs_ar_invoices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('invoice_number', 48)->unique();
            $t->date('invoice_date');
            $t->date('due_date')->nullable();
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('income_account_id');     // akun pendapatan (kredit)
            $t->unsignedBigInteger('receivable_account_id'); // akun piutang (debit)
            $t->string('description');
            $t->decimal('dpp', 20, 2)->default(0);
            $t->decimal('ppn_pct', 5, 2)->default(0);
            $t->decimal('ppn_amount', 20, 2)->default(0);
            $t->decimal('total', 20, 2)->default(0);
            $t->decimal('paid_amount', 20, 2)->default(0);
            $t->string('status', 12)->default('open');
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_ar_payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ar_invoice_id')->constrained('bs_ar_invoices')->cascadeOnDelete();
            $t->date('payment_date');
            $t->unsignedBigInteger('cash_account_id');
            $t->decimal('amount', 20, 2);
            $t->string('reference')->nullable();
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_ar_payments');
        Schema::dropIfExists('bs_ar_invoices');
        Schema::dropIfExists('bs_ap_payments');
        Schema::dropIfExists('bs_ap_invoices');
    }
};
