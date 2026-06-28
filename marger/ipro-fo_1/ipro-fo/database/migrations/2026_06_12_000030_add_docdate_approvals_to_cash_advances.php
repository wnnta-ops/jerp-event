<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #125 — Bon Sementara: kolom "Tanggal Terima Dokumen" (hanya finance yang boleh edit).
 * #127 — Bon Sementara: approval berjenjang (JSON approvals, mirip SIP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_cash_advances', 'received_document_date')) $t->date('received_document_date')->nullable();
            if (! Schema::hasColumn('bs_cash_advances', 'approvals'))              $t->json('approvals')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            foreach (['received_document_date', 'approvals'] as $c) {
                if (Schema::hasColumn('bs_cash_advances', $c)) $t->dropColumn($c);
            }
        });
    }
};
