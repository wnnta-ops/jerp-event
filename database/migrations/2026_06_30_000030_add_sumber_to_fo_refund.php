<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sumber refund (untuk dikelompokkan di Account Payable BO): SPR / SPRS /
 * TRANSFER_ANONIM. Diisi otomatis (turun dari kontrak.tahap atau saat batal
 * transfer anonim / SPRS) — dipakai sebagai refund_source di bs_ap_invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_refund', function (Blueprint $t): void {
            $t->string('sumber', 30)->nullable()->after('jenis');
        });
    }

    public function down(): void
    {
        Schema::table('fo_refund', function (Blueprint $t): void {
            $t->dropColumn('sumber');
        });
    }
};
