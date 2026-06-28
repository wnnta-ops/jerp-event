<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AP sub-modul: Kuitansi Hutang (voucher pembayaran multi-invoice + void)
 * & linkage Posting Invoice dari PO/SPK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_ap_kuitansis', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('kuitansi_number', 60)->unique();
            $t->date('kuitansi_date');
            $t->unsignedBigInteger('supplier_id');
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

        Schema::create('bs_ap_kuitansi_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('kuitansi_id')->constrained('bs_ap_kuitansis')->cascadeOnDelete();
            $t->unsignedBigInteger('ap_invoice_id');
            $t->decimal('amount', 20, 2);
            $t->unsignedBigInteger('payment_id')->nullable();
        });

        // Linkage Posting Invoice dari sumber (PO / SPK).
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            $t->string('source_type', 12)->nullable(); // po / spk
            $t->unsignedBigInteger('source_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            $t->dropColumn(['source_type', 'source_id']);
        });
        Schema::dropIfExists('bs_ap_kuitansi_lines');
        Schema::dropIfExists('bs_ap_kuitansis');
    }
};
