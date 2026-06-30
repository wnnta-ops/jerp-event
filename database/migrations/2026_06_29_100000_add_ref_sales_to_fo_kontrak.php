<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales FREE-TEXT (nama + no. telp) yang tampil ke customer di SPRS — dibawa dari
 * NUP (ref_sales_name/ref_sales_phone). Terpisah dari `agent_id` (master sales) yang
 * mengikat untuk SPR/Komisi/BLT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $t): void {
            $t->string('ref_sales_name')->nullable()->after('agent_id');
            $t->string('ref_sales_phone', 32)->nullable()->after('ref_sales_name');
        });
    }

    public function down(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $t): void {
            $t->dropColumn(['ref_sales_name', 'ref_sales_phone']);
        });
    }
};
