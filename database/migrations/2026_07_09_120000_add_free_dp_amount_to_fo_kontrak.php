<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $table): void {
            // Nilai Free DP = subsidi DP2+ (developer) saat skema KPR FREE DP.
            // Distempel oleh KontrakGeneratorService (0 bila skema non-Free-DP).
            $table->decimal('free_dp_amount', 15, 2)->default(0)->after('diskon');
        });
    }

    public function down(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $table): void {
            $table->dropColumn('free_dp_amount');
        });
    }
};
