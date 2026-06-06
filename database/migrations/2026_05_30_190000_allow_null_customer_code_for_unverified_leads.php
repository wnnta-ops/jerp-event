<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers created via public NUP form arrive as LEADs awaiting verification.
 * Until the admin verifies them, they should NOT consume a customer code from
 * the NumeratorService — so the column must be nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_customers', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Caller must ensure no nulls exist before rolling this back.
        Schema::table('fo_customers', function (Blueprint $table) {
            $table->string('code', 32)->nullable(false)->change();
        });
    }
};
