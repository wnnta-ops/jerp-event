<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bo_posted_at — penanda Kwitansi (pelunasan DP/Angsuran/TDP) sudah dikirim ke BO
 * "Approval UTJ & DP" (menunggu approval). Pelunasan TIDAK auto-posting GL lagi; GL
 * terbentuk saat BO pilih akun & setujui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_bkm', function (Blueprint $t): void {
            $t->timestamp('bo_posted_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('fo_bkm', function (Blueprint $t): void {
            $t->dropColumn('bo_posted_at');
        });
    }
};
