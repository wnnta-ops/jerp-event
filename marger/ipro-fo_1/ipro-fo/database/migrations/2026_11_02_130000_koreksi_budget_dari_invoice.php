<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi Budget yang diajukan dari langkah Struktur Registrasi Invoice Hutang.
 *
 * Baris yang melewati sisa budget BULAN itu diajukan koreksinya bersama invoice; koreksinya
 * masuk Approval Inbox (approval_id) dan baru menambah budget bulan itu setelah disetujui.
 * source_type/source_id = dokumen asal (invoice hutang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_budget_corrections', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_budget_corrections', 'approval_id')) {
                $t->unsignedBigInteger('approval_id')->nullable()->index()->after('status');
            }
            if (! Schema::hasColumn('bs_budget_corrections', 'source_type')) {
                $t->string('source_type', 30)->nullable()->after('approval_id');
                $t->unsignedBigInteger('source_id')->nullable()->after('source_type');
                $t->index(['source_type', 'source_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_budget_corrections', function (Blueprint $t): void {
            $t->dropIndex(['source_type', 'source_id']);
            $t->dropColumn(['approval_id', 'source_type', 'source_id']);
        });
    }
};
