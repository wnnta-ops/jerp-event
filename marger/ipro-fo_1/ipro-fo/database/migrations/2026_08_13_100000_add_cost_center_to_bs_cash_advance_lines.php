<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost center per baris realisasi bon sementara.
 *
 * Tanpa kolom ini, beban dari bon sementara masuk buku besar TANPA cost center, sehingga
 * BudgetRealizationService tidak bisa memasangkannya dengan anggaran mana pun — belanja
 * yang nyata terjadi tidak pernah muncul sebagai realisasi di Budget Control.
 *
 * Nullable: bon lama tidak punya isian ini, dan tidak semua beban memang bercost center
 * (lihat BudgetController yang juga memvalidasinya nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_cash_advance_lines', 'cost_center_id')) {
            return;
        }

        Schema::table('bs_cash_advance_lines', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->after('expense_account_id')
                ->constrained('bs_cost_centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_cash_advance_lines', 'cost_center_id')) {
            return;
        }

        Schema::table('bs_cash_advance_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
        });
    }
};
