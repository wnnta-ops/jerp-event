<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom penolakan terpisah agar `approved_by`/`approved_at` murni "penyetuju final"
 * (tidak lagi ditimpa saat reject → atribusi audit jelas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_approvals', function (Blueprint $t): void {
            $t->unsignedBigInteger('rejected_by')->nullable();
            $t->timestamp('rejected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fo_approvals', function (Blueprint $t): void {
            $t->dropColumn(['rejected_by', 'rejected_at']);
        });
    }
};
