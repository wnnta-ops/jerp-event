<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_schedules', function (Blueprint $table) {
            // Cakupan backup: FULL | DB_ALL | DB_FO | DB_BO | APP
            $table->string('scope', 20)->default('DB_ALL')->after('only_db');
        });

        // Backfill dari kolom lama only_db: true → DB_ALL (FO+BO), false → FULL (app+db).
        DB::table('backup_schedules')->where('only_db', true)->update(['scope' => 'DB_ALL']);
        DB::table('backup_schedules')->where('only_db', false)->update(['scope' => 'FULL']);
    }

    public function down(): void
    {
        Schema::table('backup_schedules', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
