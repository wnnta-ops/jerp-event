<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nominal Profit pada SPK (bs_work_orders) — informatif, opsional. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            $t->decimal('nominal_profit', 20, 2)->default(0)->after('contract_value');
        });
    }

    public function down(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            $t->dropColumn('nominal_profit');
        });
    }
};
