<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASH BOOK — Bon Sementara (uang muka operasional / panjar).
 * Pengeluaran panjar → realisasi (beban) + selisih refund/reimburse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_cash_advances', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('advance_number', 48)->unique();
            $t->date('advance_date');
            $t->string('holder');                               // pemegang bon (karyawan/divisi)
            $t->string('purpose');                              // keperluan
            $t->unsignedBigInteger('cash_account_id');          // kas/bank sumber
            $t->unsignedBigInteger('advance_account_id');       // Uang Muka Operasional
            $t->decimal('amount', 20, 2);                       // nilai panjar
            $t->string('status', 12)->default('outstanding');   // outstanding / realized
            $t->date('realized_date')->nullable();
            $t->decimal('realized_amount', 20, 2)->default(0);  // total beban riil
            $t->decimal('settlement', 20, 2)->default(0);       // + refund (kembali) / - reimburse (nombok)
            $t->unsignedBigInteger('journal_id')->nullable();       // jurnal pengeluaran
            $t->unsignedBigInteger('realize_journal_id')->nullable();// jurnal realisasi
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_cash_advance_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cash_advance_id')->constrained('bs_cash_advances')->cascadeOnDelete();
            $t->unsignedBigInteger('expense_account_id');
            $t->string('description');
            $t->decimal('amount', 20, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cash_advance_lines');
        Schema::dropIfExists('bs_cash_advances');
    }
};
