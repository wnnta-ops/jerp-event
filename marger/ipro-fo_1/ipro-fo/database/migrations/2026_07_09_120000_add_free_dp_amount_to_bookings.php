<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // Nilai Free DP (subsidi DP2+ developer) — disinkron dari FO (fo_kontrak.free_dp_amount).
            $table->decimal('free_dp_amount', 15, 2)->default(0)->after('gimmick_value');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('free_dp_amount');
        });
    }
};
