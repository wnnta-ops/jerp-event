<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AR sub-modul: Kuitansi Piutang (voucher penerimaan multi-invoice + void)
 * & Memorial Piutang (penyesuaian non-tunai + penghapusan piutang / bad debt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_ar_kuitansis', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('kuitansi_number', 60)->unique();
            $t->date('kuitansi_date');
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('cash_account_id');
            $t->string('alat_transaksi', 16)->default('transfer'); // tunai / transfer / giro
            $t->string('kasir')->nullable();
            $t->string('manual_no', 80)->nullable();
            $t->string('description')->nullable();
            $t->decimal('total', 20, 2)->default(0);
            $t->string('status', 10)->default('active');          // active / void
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('void_journal_id')->nullable();
            $t->date('voided_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_ar_kuitansi_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('kuitansi_id')->constrained('bs_ar_kuitansis')->cascadeOnDelete();
            $t->unsignedBigInteger('ar_invoice_id');
            $t->decimal('amount', 20, 2);
            $t->unsignedBigInteger('payment_id')->nullable();
        });

        Schema::create('bs_ar_memorials', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('memorial_number', 60)->unique();
            $t->date('memorial_date');
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('ar_invoice_id')->nullable();
            $t->string('trx_type', 16);                            // penambahan / penghapusan
            $t->unsignedBigInteger('counter_account_id');         // akun lawan
            $t->unsignedBigInteger('receivable_account_id');      // akun piutang
            $t->decimal('amount', 20, 2);
            $t->string('description');
            $t->string('kasir')->nullable();
            $t->string('manual_no', 80)->nullable();
            $t->boolean('is_writeoff')->default(false);           // true → penghapusan piutang (bad debt)
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_ar_memorials');
        Schema::dropIfExists('bs_ar_kuitansi_lines');
        Schema::dropIfExists('bs_ar_kuitansis');
    }
};
