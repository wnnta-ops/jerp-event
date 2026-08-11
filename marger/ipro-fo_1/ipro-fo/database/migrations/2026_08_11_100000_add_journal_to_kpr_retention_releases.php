<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jurnal & approval PER TRANCHE pada pelepasan retensi DAJAM.
 *
 * Tiap pelepasan sudah tercatat satu baris di kpr_retention_releases, tetapi jurnalnya
 * masih menumpang SATU kolom kpr_retentions.journal_id — sehingga pelepasan bertahap
 * hanya bisa dijurnal sekali; tranche kedua dst. akan menimpa bukti yang pertama.
 *
 * Kolom di bawah membuat tiap tranche punya jurnal & status approval sendiri, sesuai
 * ketentuan pemilik proses: tiap pelepasan disetujui/ditolak masing-masing.
 * Pencadangan retensinya tetap memakai kpr_retentions.journal_id (tidak diubah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_retention_releases', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('reason')
                ->constrained('bs_journals')->nullOnDelete();
            $table->string('approval_status', 20)->nullable()->after('journal_id'); // null=pending|approved|rejected
            $table->string('reject_reason', 500)->nullable()->after('approval_status');
            $table->timestamp('rejected_at')->nullable()->after('reject_reason');
        });
    }

    public function down(): void
    {
        Schema::table('kpr_retention_releases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
            $table->dropColumn(['approval_status', 'reject_reason', 'rejected_at']);
        });
    }
};
