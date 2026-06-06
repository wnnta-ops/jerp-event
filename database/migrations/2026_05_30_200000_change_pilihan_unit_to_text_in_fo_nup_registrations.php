<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pilihan Unit 1/2/3 di form NUP publik hanya referensi awal — tidak terikat
 * ke master unit (karena pemilihan unit aktual dilakukan pada hari-H launching).
 * Ubah dari FK ke kolom varchar bebas-input, dan drop FK constraint-nya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropForeign(['pilihan_unit_1_id']);
            $table->dropForeign(['pilihan_unit_2_id']);
            $table->dropForeign(['pilihan_unit_3_id']);
            $table->dropColumn(['pilihan_unit_1_id', 'pilihan_unit_2_id', 'pilihan_unit_3_id']);
        });

        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->string('pilihan_unit_1')->nullable()->after('pilihan_budget');
            $table->string('pilihan_unit_2')->nullable()->after('pilihan_unit_1');
            $table->string('pilihan_unit_3')->nullable()->after('pilihan_unit_2');
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropColumn(['pilihan_unit_1', 'pilihan_unit_2', 'pilihan_unit_3']);
        });

        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->foreignId('pilihan_unit_1_id')->nullable()->after('pilihan_budget')
                ->constrained('fo_units')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('pilihan_unit_2_id')->nullable()->after('pilihan_unit_1_id')
                ->constrained('fo_units')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('pilihan_unit_3_id')->nullable()->after('pilihan_unit_2_id')
                ->constrained('fo_units')->cascadeOnUpdate()->nullOnDelete();
        });
    }
};
