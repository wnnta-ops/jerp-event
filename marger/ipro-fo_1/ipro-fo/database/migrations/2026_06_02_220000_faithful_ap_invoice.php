<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AP Invoice Hutang faithful (eProperty): Kategori Invoice + tipe transaksi + PPN/PPh
 * + Detil Tagihan (lines) + Struktur (jadwal cicilan).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Master Kategori Invoice (kategori → akun debit + akun hutang) ──
        Schema::create('bs_ap_categories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name');
            $t->unsignedBigInteger('debit_account_id');     // Acc. Link / Debit
            $t->unsignedBigInteger('payable_account_id');   // Acc. Hutang Supplier
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        // ── Perluas bs_ap_invoices ──
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            $t->unsignedBigInteger('category_id')->nullable();
            $t->string('supplier_invoice_no', 80)->nullable();
            $t->string('manual_no', 80)->nullable();
            $t->string('trx_type', 16)->default('normal'); // normal / denda / saldo_awal
            $t->date('receive_date')->nullable();
            $t->unsignedInteger('term_days')->nullable();
            $t->string('transfer_detail')->nullable();
            $t->decimal('kurs', 16, 4)->default(1);
            $t->string('ppn_mode', 8)->default('none');     // none / with
            $t->string('pph_mode', 12)->default('none');      // none / withheld
            $t->decimal('pph_pct', 6, 2)->default(0);
            $t->decimal('pph_amount', 20, 2)->default(0);
            $t->unsignedBigInteger('pph_account_id')->nullable();
            $t->decimal('net_payable', 20, 2)->default(0);       // total - pph (yang dibayar ke supplier)
        });

        // ── Detil Tagihan (langkah 3) ──
        Schema::create('bs_ap_invoice_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ap_invoice_id')->constrained('bs_ap_invoices')->cascadeOnDelete();
            $t->string('description');
            $t->decimal('qty', 16, 2)->default(1);
            $t->decimal('unit_price', 20, 2)->default(0);
            $t->decimal('amount', 20, 2)->default(0);
        });

        // ── Struktur / jadwal pembayaran (langkah 4) ──
        Schema::create('bs_ap_invoice_schedules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ap_invoice_id')->constrained('bs_ap_invoices')->cascadeOnDelete();
            $t->unsignedSmallInteger('installment_no');
            $t->date('due_date');
            $t->decimal('amount', 20, 2);
            $t->decimal('paid_amount', 20, 2)->default(0);
            $t->string('status', 12)->default('unpaid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_ap_invoice_schedules');
        Schema::dropIfExists('bs_ap_invoice_lines');
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            $t->dropColumn([
                'category_id', 'supplier_invoice_no', 'manual_no', 'trx_type', 'receive_date', 'term_days',
                'transfer_detail', 'kurs', 'ppn_mode', 'pph_mode', 'pph_pct', 'pph_amount', 'pph_account_id', 'net_payable',
            ]);
        });
        Schema::dropIfExists('bs_ap_categories');
    }
};
