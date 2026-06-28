<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUSINESS SUPPORT — Budget Control (anggaran per akun & cost center per tahun).
 * Realisasi dihitung dari jurnal terposting; modul ini menyimpan rencana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_budgets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedSmallInteger('year');
            $t->unsignedBigInteger('cost_center_id')->nullable();
            $t->unsignedBigInteger('account_id');
            $t->decimal('amount', 20, 2)->default(0);
            $t->string('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'year', 'cost_center_id', 'account_id'], 'bs_budget_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_budgets');
    }
};
