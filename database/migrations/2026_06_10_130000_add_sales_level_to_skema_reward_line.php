<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasikan "Level" di Rumus Reward dengan master Level Sales (fo_sales_levels)
 * — ganti enum level dengan FK sales_level_id. Kolom enum lama dibiarkan (nullable)
 * agar tak destruktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_skema_reward_line', function (Blueprint $table) {
            $table->foreignId('sales_level_id')->nullable()->after('skema_reward_id')
                ->constrained('fo_sales_levels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fo_skema_reward_line', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_level_id');
        });
    }
};
