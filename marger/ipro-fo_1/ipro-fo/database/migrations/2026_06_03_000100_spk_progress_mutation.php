<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPK Mutasi Progress (BO MutasiProgressRegis1) — reklasifikasi CIP antar cost-center / akun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_work_orders', 'progress_mutated')) {
                $t->decimal('progress_mutated', 18, 2)->default(0);
            }
        });

        if (! Schema::hasTable('bs_work_order_progress_mutations')) {
            Schema::create('bs_work_order_progress_mutations', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('work_order_id')->index();
                $t->date('mutation_date');
                $t->decimal('amount', 18, 2);
                $t->unsignedBigInteger('from_account_id');
                $t->unsignedBigInteger('from_cost_center_id')->nullable();
                $t->unsignedBigInteger('to_account_id');
                $t->unsignedBigInteger('to_cost_center_id')->nullable();
                $t->unsignedBigInteger('journal_id')->nullable();
                $t->string('note', 255)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_work_order_progress_mutations');
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            if (Schema::hasColumn('bs_work_orders', 'progress_mutated')) {
                $t->dropColumn('progress_mutated');
            }
        });
    }
};
