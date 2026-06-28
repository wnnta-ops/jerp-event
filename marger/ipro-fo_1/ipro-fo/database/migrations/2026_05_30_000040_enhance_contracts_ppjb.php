<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Contract Lifecycle Enhancement.
 *
 * PPJB 4-state machine + dual numbering + tanggal triplet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            // PPJB Status
            $table->string('ppjb_status', 30)->default('belum_diproses');

            // Dual numbering: system vs manual (legacy migration support)
            $table->string('ppjb_system_number', 50)->nullable();
            $table->string('ppjb_manual_number', 50)->nullable();
            $table->boolean('ppjb_use_manual_number')->default(false);

            // Tanggal triplet
            $table->date('ppjb_date')->nullable();
            $table->date('ppjb_printed_at')->nullable();
            $table->date('ppjb_signed_at')->nullable();

            $table->index('ppjb_status');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropIndex(['ppjb_status']);
            $table->dropColumn([
                'ppjb_status',
                'ppjb_system_number', 'ppjb_manual_number', 'ppjb_use_manual_number',
                'ppjb_date', 'ppjb_printed_at', 'ppjb_signed_at',
            ]);
        });
    }
};
