<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #153 — Jurnal otomatis BG/Cek Keluar.
 * Tautan jurnal per peristiwa siklus agar tidak double-posting & mudah ditelusuri:
 *  - handover_journal_id : saat diserahkan ke vendor (beredar)   → Dr Hutang / Cr BG-Cek Beredar
 *  - clear_journal_id    : saat dicairkan                        → Dr BG-Cek Beredar / Cr Bank
 *  - void_journal_id     : pembalik jurnal handover bila di-void  → Dr BG-Cek Beredar / Cr Hutang
 * Semua nullable: jurnal hanya terbentuk bila akun GL bisa diresolusi (konservatif).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_outgoing_cheques', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_outgoing_cheques', 'handover_journal_id')) {
                $t->unsignedBigInteger('handover_journal_id')->nullable();
            }
            if (! Schema::hasColumn('bs_outgoing_cheques', 'clear_journal_id')) {
                $t->unsignedBigInteger('clear_journal_id')->nullable();
            }
            if (! Schema::hasColumn('bs_outgoing_cheques', 'void_journal_id')) {
                $t->unsignedBigInteger('void_journal_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_outgoing_cheques', function (Blueprint $t): void {
            foreach (['handover_journal_id', 'clear_journal_id', 'void_journal_id'] as $c) {
                if (Schema::hasColumn('bs_outgoing_cheques', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
