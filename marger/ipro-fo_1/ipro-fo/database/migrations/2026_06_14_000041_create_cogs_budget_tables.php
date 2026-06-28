<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CG-2/CG-3: Budget proyek (per cluster/kategori/COA) + Mutasi Budget (+approval) + Realisasi (Saldo Awal & realisasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_cogs_budgets')) {
            Schema::create('bs_cogs_budgets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->integer('year')->index();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('location_id')->nullable(); // cluster/tower
                $table->unsignedBigInteger('category_id');
                $table->unsignedBigInteger('account_id')->nullable();  // COA
                $table->decimal('amount', 20, 2)->default(0);          // alokasi
                $table->string('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'project_id', 'year']);
            });
        }

        if (! Schema::hasTable('bs_cogs_budget_mutations')) {
            Schema::create('bs_cogs_budget_mutations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('doc_no')->nullable();
                $table->integer('year')->index();
                $table->unsignedBigInteger('project_id');
                $table->string('kind')->default('transfer');           // transfer|koreksi
                $table->unsignedBigInteger('from_budget_id')->nullable();
                $table->unsignedBigInteger('to_budget_id')->nullable();
                $table->decimal('amount', 20, 2)->default(0);
                $table->date('mutation_date');
                $table->string('status')->default('baru');              // baru|approved|rejected
                $table->string('note')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bs_cogs_realizations')) {
            Schema::create('bs_cogs_realizations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('doc_no')->nullable();
                $table->integer('year')->index();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('location_id')->nullable();
                $table->unsignedBigInteger('category_id')->nullable();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->date('realization_date');
                $table->decimal('amount', 20, 2)->default(0);
                $table->string('type')->default('saldo_awal');         // saldo_awal|realisasi
                $table->string('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cogs_realizations');
        Schema::dropIfExists('bs_cogs_budget_mutations');
        Schema::dropIfExists('bs_cogs_budgets');
    }
};
