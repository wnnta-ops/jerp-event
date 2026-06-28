<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Enhance payment_schedules dengan:
 *   - payment_type (BF/DP/ANG/ADM)
 *   - sequence (untuk numbering .1/.2/.3)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_schedules', function (Blueprint $table): void {
            $table->string('payment_type', 5)->default('DP');
            // sequence dalam scope booking (1, 2, 3, ...)  → dipakai untuk display .1/.2/.3
            $table->unsignedSmallInteger('sequence')->nullable();
            $table->index(['booking_id', 'payment_type']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_schedules', function (Blueprint $table): void {
            $table->dropIndex(['booking_id', 'payment_type']);
            $table->dropColumn(['payment_type', 'sequence']);
        });
    }
};
