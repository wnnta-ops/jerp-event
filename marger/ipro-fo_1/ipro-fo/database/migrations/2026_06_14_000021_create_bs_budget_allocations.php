<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BC-2: Alokasi Budget (BO: Alokasi Budget Control, dok BC.AL/...).
 * Memesan/mengalokasikan sebagian anggaran master untuk periode tertentu; tunduk approval batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_budget_allocations')) {
            return;
        }
        Schema::create('bs_budget_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('doc_no')->nullable();
            $table->integer('year')->index();
            $table->string('project')->nullable();
            $table->foreignId('budget_id')->nullable()->constrained('bs_budgets')->nullOnDelete();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->unsignedBigInteger('account_id');
            $table->date('alloc_date');
            $table->unsignedTinyInteger('period_from')->default(1);  // 1..12
            $table->unsignedTinyInteger('period_to')->default(12);   // 1..12
            $table->decimal('amount', 20, 2)->default(0);
            $table->decimal('total_budget', 20, 2)->default(0);       // snapshot saat alokasi
            $table->string('status')->default('baru');                // baru|approved|rejected
            $table->string('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_budget_allocations');
    }
};
