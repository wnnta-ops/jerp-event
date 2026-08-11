<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda ASAL penerimaan, untuk memisah dua submodul approval di Cash Book:
 *   null  → Approval UTJ  (UTJ, transfer anonim, kwitansi umum — perilaku lama)
 *   'dp'  → Approval DP   (Pelunasan DP, Angsuran & Tambah DP dari FO)
 *
 * Sengaja nullable tanpa nilai bawaan 'utj': seluruh data lama tetap muncul di Approval UTJ
 * persis seperti sebelumnya, tanpa perlu backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_receipts', function (Blueprint $t): void {
            if (! Schema::hasColumn('tts_receipts', 'approval_source')) {
                $t->string('approval_source', 16)->nullable()->after('status');
                $t->index('approval_source', 'tts_receipts_approval_source_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tts_receipts', function (Blueprint $t): void {
            $t->dropIndex('tts_receipts_approval_source_idx');
            $t->dropColumn('approval_source');
        });
    }
};
