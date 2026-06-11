<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval 2-level + eksekusi (selaras legacy: KontrakApprov[Type] + [Type]2 = Level 1 dan Level 2).
 * current_level: 1 = menunggu approver L1, 2 = menunggu approver L2.
 * Level 2 (final) memakai kolom approved_by/approved_at/catatan_approver yang sudah ada.
 * Saat L2 approve → ApprovalExecutionService menerapkan perubahan ke Kontrak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_approvals', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_level')->default(1)->after('status');
            $table->foreignId('level1_by')->nullable()->after('current_level')->constrained('users')->nullOnDelete();
            $table->timestamp('level1_at')->nullable()->after('level1_by');
            $table->text('level1_note')->nullable()->after('level1_at');
            $table->timestamp('executed_at')->nullable()->after('approved_at');
            $table->text('executed_result')->nullable()->after('executed_at');
        });
    }

    public function down(): void
    {
        Schema::table('fo_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('level1_by');
            $table->dropColumn(['current_level', 'level1_at', 'level1_note', 'executed_at', 'executed_result']);
        });
    }
};
