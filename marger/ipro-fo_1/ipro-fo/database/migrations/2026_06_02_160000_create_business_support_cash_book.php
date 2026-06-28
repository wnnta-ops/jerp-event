<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUSINESS SUPPORT — Cash Book (Kas/Bank).
 * Kas Masuk (BKM), Kas Keluar (BKK), Transfer Antar Rekening — dengan jurnal GL otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_cash_transactions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('trx_number', 48)->unique();
            $t->string('trx_type', 12);                         // in / out / transfer
            $t->date('trx_date');
            $t->unsignedBigInteger('cash_account_id');          // rekening kas/bank (sumber)
            $t->unsignedBigInteger('counter_account_id');       // lawan; transfer = rekening tujuan
            $t->decimal('amount', 20, 2);
            $t->string('payee')->nullable();                    // penerima / pembayar
            $t->string('description');
            $t->string('reference')->nullable();
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['trx_type', 'trx_date']);
            $t->index('cash_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cash_transactions');
    }
};
