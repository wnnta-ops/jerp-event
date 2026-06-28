<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUSINESS SUPPORT — GENERAL LEDGER (jurnal double-entry, periode fiskal, buku besar).
 * Meniru modul GL eProperty. Chart of Account memakai tabel bs_accounts (sudah ada).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Periode Fiskal ──
        Schema::create('bs_fiscal_periods', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->string('status', 12)->default('open');   // open / final / approved / closed
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'year', 'month']);
        });

        // ── Jurnal (header) ──
        Schema::create('bs_journals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('cost_center_id')->nullable();
            $t->string('journal_number', 48)->unique();
            $t->date('journal_date');
            $t->unsignedSmallInteger('period_year');
            $t->unsignedTinyInteger('period_month');
            $t->string('source', 24)->default('memorial'); // memorial / saldo_awal / ar / ap / cash / inventory
            $t->string('reference')->nullable();
            $t->string('description');
            $t->string('status', 12)->default('posted');    // draft / posted / void
            $t->decimal('total_debit', 20, 2)->default(0);
            $t->decimal('total_credit', 20, 2)->default(0);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['period_year', 'period_month']);
            $t->index('source');
        });

        // ── Jurnal (baris debit/kredit) ──
        Schema::create('bs_journal_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('journal_id')->constrained('bs_journals')->cascadeOnDelete();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('cost_center_id')->nullable();
            $t->string('description')->nullable();
            $t->decimal('debit', 20, 2)->default(0);
            $t->decimal('credit', 20, 2)->default(0);
            $t->timestamps();
            $t->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_journal_lines');
        Schema::dropIfExists('bs_journals');
        Schema::dropIfExists('bs_fiscal_periods');
    }
};
