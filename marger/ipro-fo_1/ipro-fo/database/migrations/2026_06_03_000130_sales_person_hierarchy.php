<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Person — tipe (arebi/non-arebi) + level (kantor/koordinator/sales).
 * Atasan memakai kolom manager_id yang sudah ada (koordinator → kantor, sales → koordinator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_persons', function (Blueprint $t): void {
            $t->string('type', 16)->default('non_arebi');   // arebi | non_arebi
            $t->string('level', 16)->default('sales');      // kantor | koordinator | sales
        });
    }

    public function down(): void
    {
        Schema::table('sales_persons', function (Blueprint $t): void {
            $t->dropColumn(['type', 'level']);
        });
    }
};
