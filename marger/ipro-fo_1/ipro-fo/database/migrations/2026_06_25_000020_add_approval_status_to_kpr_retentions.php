<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval Retensi — status penolakan (mirror Approval UTJ & DP). approval_status:
 * null = menunggu/disetujui (disetujui = journal_id terisi), 'rejected' = ditolak (+ reject_reason).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_retentions', function (Blueprint $table) {
            if (! Schema::hasColumn('kpr_retentions', 'approval_status')) {
                $table->string('approval_status', 20)->nullable();
            }
            if (! Schema::hasColumn('kpr_retentions', 'reject_reason')) {
                $table->string('reject_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpr_retentions', function (Blueprint $table) {
            foreach (['approval_status', 'reject_reason'] as $col) {
                if (Schema::hasColumn('kpr_retentions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
