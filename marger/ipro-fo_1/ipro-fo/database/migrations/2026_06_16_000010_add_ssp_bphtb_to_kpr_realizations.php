<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #146 — DAJAM Pajak: input pembayaran SSP/BPHTB pada realisasi pencairan KPR.
 *  - ssp_bphtb_date   : tanggal bayar SSP/BPHTB
 *  - ssp_bphtb_amount : nominal bayar SSP/BPHTB (mengurangi outstanding AR customer via auto-allocate TTS)
 *  - ssp_tts_id       : TTS pembayaran SSP/BPHTB yang ter-auto-generate (untuk reversal aman saat hapus realisasi)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_realizations', function (Blueprint $t): void {
            if (! Schema::hasColumn('kpr_realizations', 'ssp_bphtb_date')) {
                $t->date('ssp_bphtb_date')->nullable();
            }
            if (! Schema::hasColumn('kpr_realizations', 'ssp_bphtb_amount')) {
                $t->decimal('ssp_bphtb_amount', 20, 2)->default(0);
            }
            if (! Schema::hasColumn('kpr_realizations', 'ssp_tts_id')) {
                $t->unsignedBigInteger('ssp_tts_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpr_realizations', function (Blueprint $t): void {
            foreach (['ssp_bphtb_date', 'ssp_bphtb_amount', 'ssp_tts_id'] as $col) {
                if (Schema::hasColumn('kpr_realizations', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
