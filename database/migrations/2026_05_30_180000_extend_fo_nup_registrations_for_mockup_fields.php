<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds fields from the public NUP mockup that didn't exist yet:
 * - pilihan_budget: multi-select budget categories (Rumah 200jt / 400jt / RUKO / ...)
 * - pilihan_unit_1..3: 3 non-binding unit picks from fo_units master
 * - secondary_phone: optional second contact number (toggled via "Tambah Nomor")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->json('pilihan_budget')->nullable()->after('preferensi_unit');
            $table->foreignId('pilihan_unit_1_id')->nullable()->after('pilihan_budget')
                ->constrained('fo_units')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('pilihan_unit_2_id')->nullable()->after('pilihan_unit_1_id')
                ->constrained('fo_units')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('pilihan_unit_3_id')->nullable()->after('pilihan_unit_2_id')
                ->constrained('fo_units')->cascadeOnUpdate()->nullOnDelete();
            $table->string('secondary_phone', 32)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropForeign(['pilihan_unit_1_id']);
            $table->dropForeign(['pilihan_unit_2_id']);
            $table->dropForeign(['pilihan_unit_3_id']);
            $table->dropColumn(['pilihan_budget', 'pilihan_unit_1_id', 'pilihan_unit_2_id', 'pilihan_unit_3_id', 'secondary_phone']);
        });
    }
};
