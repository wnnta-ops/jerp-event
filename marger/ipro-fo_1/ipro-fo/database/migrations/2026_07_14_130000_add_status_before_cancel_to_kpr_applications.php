<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyimpan status tepat sebelum di-Batal (Cancelled) agar saat keputusan diubah ke Lanjut
 * (Ubah → Lanjut) proses KPR kembali ke tahap terakhirnya — bukan mundur ke Submitted /
 * melompati SI. Dengan tahap tambahan (Wawancara..Banding), sp3k()->exists() tak lagi cukup
 * untuk menebak tahap terakhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_applications', function (Blueprint $table) {
            $table->string('status_before_cancel', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('kpr_applications', function (Blueprint $table) {
            $table->dropColumn('status_before_cancel');
        });
    }
};
