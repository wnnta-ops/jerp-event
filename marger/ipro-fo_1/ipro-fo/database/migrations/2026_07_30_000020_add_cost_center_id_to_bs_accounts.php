<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Center menjadi ATRIBUT akun (Chart of Account): tiap akun boleh dipetakan ke satu
 * cost center (bs_cost_centers). Saat Input Jurnal manual, cost center tiap baris diturunkan
 * otomatis dari akun-nya & dikunci — tidak lagi dipilih per transaksi di header jurnal.
 * Nullable: akun yang belum dipetakan tetap sah (baris jurnal tanpa cost center).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_accounts', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_accounts', 'cost_center_id')) {
                $t->unsignedBigInteger('cost_center_id')->nullable()->after('cf_indirect_category_id');
            }
        });

        if (! $this->hasIndex('bs_accounts', 'bs_accounts_cost_center_idx')) {
            Schema::table('bs_accounts', function (Blueprint $t): void {
                $t->index('cost_center_id', 'bs_accounts_cost_center_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('bs_accounts', function (Blueprint $t): void {
            if ($this->hasIndex('bs_accounts', 'bs_accounts_cost_center_idx')) {
                $t->dropIndex('bs_accounts_cost_center_idx');
            }
            if (Schema::hasColumn('bs_accounts', 'cost_center_id')) {
                $t->dropColumn('cost_center_id');
            }
        });
    }

    /** Cek keberadaan index secara aman (idempoten walau kolom/index sudah diterapkan manual ke DB). */
    private function hasIndex(string $table, string $index): bool
    {
        $rows = Schema::getConnection()->select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$index],
        );

        return count($rows) > 0;
    }
};
