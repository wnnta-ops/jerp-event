<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * numerators.reset — 'tidak' = nomor urut TIDAK PERNAH kembali ke 1 walau formatnya memuat
 * tahun/bulan (mis. SPR warisan eProperty: 001669/SPR/JR/X/2026 bersambung lintas tahun).
 * NULL = ikut format seperti sebelumnya ({DD} harian, {MM} bulanan, {YYYY} tahunan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('numerators', 'reset')) {
            Schema::table('numerators', function (Blueprint $t): void {
                $t->string('reset', 10)->nullable()->after('period');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('numerators', 'reset')) {
            Schema::table('numerators', function (Blueprint $t): void {
                $t->dropColumn('reset');
            });
        }
    }
};
