<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profit SPK diinput sebagai PERSENTASE (bukan nominal lagi).
 * nominal_profit tetap ada & tetap diisi — hasil hitungan dari profit_pct
 * (lihat ContractorService::profitAmount) supaya data lama tidak berubah arti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            $t->decimal('profit_pct', 6, 2)->default(0)->after('nominal_profit');
        });
    }

    public function down(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            $t->dropColumn('profit_pct');
        });
    }
};
