<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier dobel yang digabung (ap:gabung-supplier) mencatat ke baris mana ia digabung, supaya
 * penerima Reimburse/Deposit (KRY-/CST-) berikutnya memakai baris itu — bukan menghidupkan lagi
 * baris dobelnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bs_suppliers', 'digabung_ke_id')) {
            Schema::table('bs_suppliers', function (Blueprint $t): void {
                $t->unsignedBigInteger('digabung_ke_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bs_suppliers', 'digabung_ke_id')) {
            Schema::table('bs_suppliers', function (Blueprint $t): void {
                $t->dropIndex(['digabung_ke_id']);
                $t->dropColumn('digabung_ke_id');
            });
        }
    }
};
