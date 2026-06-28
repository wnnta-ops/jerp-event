<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Enhance memos table untuk support Free DP / Subsidi DP / Adjustment.
 *
 *   - Drop ENUM constraint on `type` (legacy = incoming/outgoing only)
 *   - Tambah approval fields: approved_by, approved_at, rejected_*, cancelled_*
 *   - Tambah reason / approval_id reference
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: change `type` column from ENUM to VARCHAR(32) untuk extend values.
        // SQLite tidak support ALTER COLUMN ENUM → MariaDB butuh modify. Pakai if-driver guard.
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement('ALTER TABLE memos MODIFY type VARCHAR(32) NOT NULL');
        }
        // SQLite — type already stored as TEXT via Eloquent cast, no schema change needed.

        Schema::table('memos', function (Blueprint $table): void {
            // Approval workflow fields
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('memos', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropForeign(['cancelled_by']);
            $table->dropForeign(['approval_id']);
            $table->dropColumn([
                'approved_by', 'approved_at',
                'rejected_by', 'rejected_at',
                'cancelled_by', 'cancelled_at',
                'reason', 'approval_id',
            ]);
        });

        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("ALTER TABLE memos MODIFY type ENUM('incoming','outgoing') NOT NULL");
        }
    }
};
