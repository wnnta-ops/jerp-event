<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lengkapi fo_skema untuk builder skema bayar (Setup Sales legacy):
 * - cara_bayar (Jenis): KPR / CASH_BERTAHAP / CASH_KERAS  → jadikan string biar fleksibel
 * - tampil_siteplan: checkbox "Site Plan" (muncul di siteplan publik atau tidak)
 * - present_value, diskon_persen (% bertingkat — disimpan nilai dasar)
 * - bf_dipotong_di: DP1 / ANGSURAN1 / DP_DISEBAR / ANGSURAN_DISEBAR / TIDAK_DISEBAR
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_skema', function (Blueprint $table) {
            $table->string('cara_bayar', 24)->default('KPR')->change();
            $table->boolean('tampil_siteplan')->default(true)->after('is_active');
            $table->decimal('present_value', 18, 2)->default(0)->after('jumlah_termin');
            $table->decimal('diskon_persen', 5, 2)->default(0)->after('present_value');
            $table->string('bf_dipotong_di', 24)->default('TIDAK_DISEBAR')->after('diskon_persen');
        });
    }

    public function down(): void
    {
        Schema::table('fo_skema', function (Blueprint $table) {
            $table->dropColumn(['tampil_siteplan', 'present_value', 'diskon_persen', 'bf_dipotong_di']);
        });
    }
};
