<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anotasi per-mutasi pada Rekening Ledger (Cash Book):
 *  - bs_ledger_cf_tags        : kategori arus kas (Direct & Indirect) per baris jurnal → laporan Arus Kas.
 *  - bs_ledger_cluster_splits : pembagian biaya per cluster per baris jurnal → Arus Kas per cluster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_ledger_cf_tags', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('journal_line_id')->constrained('bs_journal_lines')->cascadeOnDelete();
            $t->foreignId('cf_direct_category_id')->nullable()->constrained('bs_cash_flow_categories')->nullOnDelete();
            $t->foreignId('cf_indirect_category_id')->nullable()->constrained('bs_cash_flow_categories')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'journal_line_id']);
        });

        Schema::create('bs_ledger_cluster_splits', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('journal_line_id')->constrained('bs_journal_lines')->cascadeOnDelete();
            $t->foreignId('cluster_id')->constrained('clusters');
            $t->decimal('amount', 20, 2)->default(0);
            $t->string('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['tenant_id', 'journal_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_ledger_cluster_splits');
        Schema::dropIfExists('bs_ledger_cf_tags');
    }
};
