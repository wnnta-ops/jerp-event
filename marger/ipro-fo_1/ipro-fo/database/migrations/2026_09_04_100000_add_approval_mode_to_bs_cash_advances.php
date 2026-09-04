<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cara persetujuan bon sementara — dipilih PER PENGAJUAN.
 *
 *   online  : persetujuan berjenjang di aplikasi (pengaju s.d. Manager Finance). Pembayaran &
 *             jurnal baru terbuka setelah seluruh tanda tangan terpenuhi.
 *   offline : tanda tangannya di kertas. Jenjang di aplikasi dimatikan, dan pembayaran boleh
 *             dicatat tanpa menunggu tanda tangan digital.
 *
 * BAWAANNYA 'online' — sama dengan satu-satunya perilaku yang ada selama ini, sehingga
 * seluruh bon lama tetap menuntut persetujuan lengkap persis seperti sebelum kolom ini ada.
 * Kalau bawaannya 'offline', bon yang sudah telanjur diajukan mendadak bisa dibayar tanpa
 * satu pun persetujuan — dan tidak ada yang menandainya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_cash_advances', 'approval_mode')) {
            return;
        }

        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            $t->string('approval_mode', 16)->default('online')->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_cash_advances', 'approval_mode')) {
            return;
        }

        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            $t->dropColumn('approval_mode');
        });
    }
};
