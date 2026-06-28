<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BC-3: Koreksi Budget (BO: Koreksi Budget Control, dok BC.MA/...).
 * Mengoreksi nilai anggaran master: Nilai Lama → Nilai Baru; berlaku setelah di-approve (batch).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_budget_corrections')) {
            return;
        }
        Schema::create('bs_budget_corrections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('doc_no')->nullable();
            $table->integer('year')->index();
            $table->string('project')->nullable();
            $table->foreignId('budget_id')->nullable()->constrained('bs_budgets')->nullOnDelete();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->unsignedBigInteger('account_id');
            $table->date('corr_date');
            $table->unsignedTinyInteger('period_from')->default(1);
            $table->unsignedTinyInteger('period_to')->default(12);
            $table->decimal('old_amount', 20, 2)->default(0);
            $table->decimal('new_amount', 20, 2)->default(0);
            $table->string('status')->default('baru');                // baru|approved|rejected
            $table->string('note')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_budget_corrections');
    }
};
