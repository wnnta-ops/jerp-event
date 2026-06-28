<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3 — Budget workflow (Alokasi → Approval) + Mutasi/Koreksi anggaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_budgets', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_budgets', 'status')) {
                $t->string('status', 20)->default('draft'); // draft|submitted|approved|rejected
            }
            if (! Schema::hasColumn('bs_budgets', 'submitted_at')) {
                $t->timestamp('submitted_at')->nullable();
            }
            if (! Schema::hasColumn('bs_budgets', 'approved_at')) {
                $t->timestamp('approved_at')->nullable();
            }
            if (! Schema::hasColumn('bs_budgets', 'approved_by')) {
                $t->unsignedBigInteger('approved_by')->nullable();
            }
            if (! Schema::hasColumn('bs_budgets', 'approval_note')) {
                $t->string('approval_note', 255)->nullable();
            }
        });

        if (! Schema::hasTable('bs_budget_mutations')) {
            Schema::create('bs_budget_mutations', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->integer('year')->index();
                $t->string('kind', 20);                        // transfer | koreksi
                $t->unsignedBigInteger('from_budget_id')->nullable();
                $t->unsignedBigInteger('to_budget_id')->nullable();
                $t->decimal('amount', 18, 2);
                $t->date('mutation_date');
                $t->string('note', 255)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_budget_mutations');
        Schema::table('bs_budgets', function (Blueprint $t): void {
            foreach (['status', 'submitted_at', 'approved_at', 'approved_by', 'approval_note'] as $c) {
                if (Schema::hasColumn('bs_budgets', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
