<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CB-3 — Approval Pengeluaran Kas & Bank: status persetujuan untuk transaksi keluar (BKK).
 * Transaksi lama di-backfill 'approved' agar tidak menumpuk di antrian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_cash_transactions', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_cash_transactions', 'approval_status')) {
                $t->string('approval_status', 12)->default('pending'); // pending | approved
            }
            if (! Schema::hasColumn('bs_cash_transactions', 'approved_by')) {
                $t->unsignedBigInteger('approved_by')->nullable();
            }
            if (! Schema::hasColumn('bs_cash_transactions', 'approved_at')) {
                $t->timestamp('approved_at')->nullable();
            }
        });

        // Backfill: semua transaksi yang sudah ada dianggap sudah disetujui.
        DB::table('bs_cash_transactions')->update(['approval_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('bs_cash_transactions', function (Blueprint $t): void {
            foreach (['approved_at', 'approved_by', 'approval_status'] as $c) {
                if (Schema::hasColumn('bs_cash_transactions', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
