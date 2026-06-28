<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #150 — Bon Sementara: kolom department_id (FK ke bs_hr_departments) agar
 * laporan bisa difilter & ditotal per departemen pemegang bon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_cash_advances', 'department_id')) {
                $t->unsignedBigInteger('department_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            if (Schema::hasColumn('bs_cash_advances', 'department_id')) {
                $t->dropColumn('department_id');
            }
        });
    }
};
