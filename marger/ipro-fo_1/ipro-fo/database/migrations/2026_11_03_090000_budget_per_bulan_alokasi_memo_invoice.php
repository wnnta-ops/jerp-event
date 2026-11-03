<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting Budget Control 3 Nov 2026:
 *  - Koreksi Budget per bulan: `months` = tambahan per bulan {"11": 5000000, ...}.
 *  - Alokasi = pindah anggaran budget A → budget B per bulan (tanpa approval): `to_budget_id`
 *    + `months` = nilai yang dipindah per bulan; `source_*` bila diajukan dari invoice hutang.
 *  - Invoice hutang menarik satu Memo Urgent yang sudah disetujui Direktur: `memo_internal_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_budget_corrections', function (Blueprint $table): void {
            if (! Schema::hasColumn('bs_budget_corrections', 'months')) {
                $table->json('months')->nullable()->after('new_amount');
            }
        });
        Schema::table('bs_budget_allocations', function (Blueprint $table): void {
            if (! Schema::hasColumn('bs_budget_allocations', 'to_budget_id')) {
                $table->unsignedBigInteger('to_budget_id')->nullable()->index()->after('budget_id');
                $table->json('months')->nullable()->after('amount');
                $table->string('source_type', 30)->nullable()->after('status');
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            }
        });
        Schema::table('bs_ap_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('bs_ap_invoices', 'memo_internal_id')) {
                $table->unsignedBigInteger('memo_internal_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_invoices', fn (Blueprint $t) => $t->dropColumn('memo_internal_id'));
        Schema::table('bs_budget_allocations', fn (Blueprint $t) => $t->dropColumn(['to_budget_id', 'months', 'source_type', 'source_id']));
        Schema::table('bs_budget_corrections', fn (Blueprint $t) => $t->dropColumn('months'));
    }
};
