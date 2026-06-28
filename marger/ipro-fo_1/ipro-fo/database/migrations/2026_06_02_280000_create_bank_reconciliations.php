<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekonsiliasi Bank — cocokkan saldo buku (GL kas/bank) vs rekening koran.
 *   Header: per akun bank per tanggal koran + saldo koran.
 *   Lines : mutasi GL kas/bank yang ditandai cleared (muncul di koran) atau outstanding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_bank_reconciliations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('account_id');                 // akun kas/bank GL
            $t->date('statement_date');                           // tanggal rekening koran
            $t->decimal('statement_balance', 20, 2)->default(0);  // saldo akhir koran
            $t->decimal('book_balance', 20, 2)->default(0);       // saldo buku GL s/d tanggal (snapshot)
            $t->string('status', 12)->default('open');            // open / reconciled
            $t->string('notes')->nullable();
            $t->date('reconciled_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_bank_rec_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('reconciliation_id')->constrained('bs_bank_reconciliations')->cascadeOnDelete();
            $t->unsignedBigInteger('journal_line_id');
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->date('tx_date');
            $t->string('description')->nullable();
            $t->decimal('debit', 20, 2)->default(0);              // kas masuk
            $t->decimal('credit', 20, 2)->default(0);             // kas keluar
            $t->boolean('is_cleared')->default(false);            // sudah muncul di koran
            $t->index(['journal_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_bank_rec_lines');
        Schema::dropIfExists('bs_bank_reconciliations');
    }
};
