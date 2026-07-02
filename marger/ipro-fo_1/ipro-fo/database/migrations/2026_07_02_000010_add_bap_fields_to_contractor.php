<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field pendukung cetak BAP (Berita Acara Pembayaran):
 * - bs_work_orders.advance_pct  : % Uang Muka (DP) tingkat kontrak SPK (default 0).
 * - bs_work_order_progress.contractor_signer : nama penandatangan pihak Kontraktor pada BAP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('bs_work_orders', 'advance_pct')) {
                $table->decimal('advance_pct', 5, 2)->default(0)->after('retention_pct');
            }
        });

        Schema::table('bs_work_order_progress', function (Blueprint $table): void {
            if (! Schema::hasColumn('bs_work_order_progress', 'contractor_signer')) {
                $table->string('contractor_signer', 100)->nullable()->after('pic');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('bs_work_orders', 'advance_pct')) {
                $table->dropColumn('advance_pct');
            }
        });

        Schema::table('bs_work_order_progress', function (Blueprint $table): void {
            if (Schema::hasColumn('bs_work_order_progress', 'contractor_signer')) {
                $table->dropColumn('contractor_signer');
            }
        });
    }
};
