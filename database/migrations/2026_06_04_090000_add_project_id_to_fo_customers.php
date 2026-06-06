<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master Customer butuh kolom Project (lihat layar legacy eProperty):
 * - project_id: project tempat customer mendaftar/membeli. Diisi otomatis saat
 *   customer dibuat dari pendaftaran NUP (project pilihan / event), lalu di-backfill
 *   untuk data lama dari pendaftaran NUP terakhir mereka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_customers', function (Blueprint $table) {
            if (! Schema::hasColumn('fo_customers', 'project_id')) {
                $table->foreignId('project_id')->nullable()->after('code')->constrained('projects')->nullOnDelete();
            }
        });

        // Backfill dari pendaftaran NUP terakhir (project pilihan, fallback project event).
        // Subquery korelasi — kompatibel MySQL 8 & SQLite.
        if (Schema::hasColumn('fo_customers', 'project_id')) {
            DB::statement(<<<'SQL'
                UPDATE fo_customers
                SET project_id = (
                    SELECT COALESCE(r.project_pref_id, e.project_id)
                    FROM fo_nup_registrations r
                    LEFT JOIN fo_nup_events e ON e.id = r.nup_event_id
                    WHERE r.customer_id = fo_customers.id
                    ORDER BY r.id DESC
                    LIMIT 1
                )
                WHERE project_id IS NULL
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('fo_customers', function (Blueprint $table) {
            if (Schema::hasColumn('fo_customers', 'project_id')) {
                $table->dropConstrainedForeignId('project_id');
            }
        });
    }
};
