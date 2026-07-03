<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan kategori arus kas per akun (Chart of Account):
 * cf_direct_category_id  → kategori Cash Flow Direct   (bs_cash_flow_categories, kind=direct)
 * cf_indirect_category_id→ kategori Cash Flow Indirect (bs_cash_flow_categories, kind=indirect)
 * Kolom denormal agar mudah dipilih di form CoA & dibaca laporan arus kas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_accounts', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_accounts', 'cf_direct_category_id')) {
                $t->unsignedBigInteger('cf_direct_category_id')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('bs_accounts', 'cf_indirect_category_id')) {
                $t->unsignedBigInteger('cf_indirect_category_id')->nullable()->after('cf_direct_category_id');
            }
        });

        Schema::table('bs_accounts', function (Blueprint $t): void {
            $t->index('cf_direct_category_id', 'bs_accounts_cf_direct_idx');
            $t->index('cf_indirect_category_id', 'bs_accounts_cf_indirect_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bs_accounts', function (Blueprint $t): void {
            $t->dropIndex('bs_accounts_cf_direct_idx');
            $t->dropIndex('bs_accounts_cf_indirect_idx');
            $t->dropColumn(['cf_direct_category_id', 'cf_indirect_category_id']);
        });
    }
};
