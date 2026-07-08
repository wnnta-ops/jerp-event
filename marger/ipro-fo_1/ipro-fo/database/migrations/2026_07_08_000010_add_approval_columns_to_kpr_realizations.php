<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval Retensi — dukung baris PENCAIRAN (KprRealization) selain retensi (KprRetention).
 * Setiap pencairan (net = disbursed_amount − retention_amount) kini juga bisa disetujui/dijurnal
 * di Cash Book → Approval Retensi (DR Bank / CR Uang Muka Penjualan + PPN Keluaran), sehingga
 * seluruh input pencairan masuk otomatis ke list approval. Kolom mengikuti pola kpr_retentions:
 *   journal_id     : jurnal GL setelah disetujui (null = belum dijurnal)
 *   approval_status: null = menunggu/disetujui, 'rejected' = ditolak (+ reject_reason)
 *   reject_reason  : alasan penolakan
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_realizations', function (Blueprint $table) {
            if (! Schema::hasColumn('kpr_realizations', 'journal_id')) {
                $table->unsignedBigInteger('journal_id')->nullable()->index();
            }
            if (! Schema::hasColumn('kpr_realizations', 'approval_status')) {
                $table->string('approval_status', 20)->nullable();
            }
            if (! Schema::hasColumn('kpr_realizations', 'reject_reason')) {
                $table->string('reject_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpr_realizations', function (Blueprint $table) {
            foreach (['journal_id', 'approval_status', 'reject_reason'] as $col) {
                if (Schema::hasColumn('kpr_realizations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
