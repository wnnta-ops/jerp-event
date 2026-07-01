<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hapus kolom fo_bkm.akun_kas — akun GL kini ditentukan di BO "Approval UTJ & DP"
 * (saat Posting BO), bukan di Kwitansi FO. Kolom sudah tak dipakai lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fo_bkm', 'akun_kas')) {
            Schema::table('fo_bkm', function (Blueprint $t): void {
                $t->dropColumn('akun_kas');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fo_bkm', 'akun_kas')) {
            Schema::table('fo_bkm', function (Blueprint $t): void {
                $t->string('akun_kas', 32)->nullable()->after('jenis_pembayaran');
            });
        }
    }
};
