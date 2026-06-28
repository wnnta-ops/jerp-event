<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #122 — SIP: status "Tidak Disetujui" (rejected) + alasan penolakan.
 * Saat ditolak, approvals direset (semua TTD dibuka) & finance pembuat boleh edit/hapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_sips', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_sips', 'rejection_reason')) $t->text('rejection_reason')->nullable();
            if (! Schema::hasColumn('bs_sips', 'rejected_by'))      $t->unsignedBigInteger('rejected_by')->nullable();
            if (! Schema::hasColumn('bs_sips', 'rejected_at'))      $t->timestamp('rejected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bs_sips', function (Blueprint $t): void {
            foreach (['rejection_reason', 'rejected_by', 'rejected_at'] as $c) {
                if (Schema::hasColumn('bs_sips', $c)) $t->dropColumn($c);
            }
        });
    }
};
