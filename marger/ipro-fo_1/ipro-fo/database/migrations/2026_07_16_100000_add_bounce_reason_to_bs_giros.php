<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alasan Cek/Giro Masuk ditolak — wajib diisi saat klik "Tolak" (Transaksi Cek Giro Masuk).
 *
 * Nullable karena warkat lama yang telanjur ditolak tidak punya alasan tercatat;
 * kewajiban isi ditegakkan di validasi (GiroController::bounce), bukan di kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_giros', function (Blueprint $t): void {
            $t->string('bounce_reason', 255)->nullable()->after('settled_date');
        });
    }

    public function down(): void
    {
        Schema::table('bs_giros', function (Blueprint $t): void {
            $t->dropColumn('bounce_reason');
        });
    }
};
