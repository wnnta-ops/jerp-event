<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nilai transfer aktual yang diterima Xendit (paid_amount) — untuk rekonsiliasi:
 * cocok bila xendit_paid_amount == utj_amount (nilai UTJ sistem).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->decimal('xendit_paid_amount', 18, 2)->nullable()->after('xendit_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropColumn('xendit_paid_amount');
        });
    }
};
