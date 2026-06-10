<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booked Manual = pendaftaran di luar launching (reuse fo_nup_registrations).
 *  - jenis: NUP (launching) | BOOKED (manual, unit langsung, tanpa Xendit)
 *  - nup_event_id jadi nullable (BOOKED tak punya event launching)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->enum('jenis', ['NUP', 'BOOKED'])->default('NUP')->after('tenant_id');
        });
        DB::statement('ALTER TABLE fo_nup_registrations MODIFY nup_event_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropColumn('jenis');
        });
        DB::statement('ALTER TABLE fo_nup_registrations MODIFY nup_event_id BIGINT UNSIGNED NOT NULL');
    }
};
