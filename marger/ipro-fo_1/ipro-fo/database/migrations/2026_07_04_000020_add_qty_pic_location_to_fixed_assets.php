<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom tambahan Aset Tetap agar report penyusutan tampil seperti lampiran:
 * JUMLAH (quantity), PIC, LOKASI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_fixed_assets', function (Blueprint $t): void {
            $t->string('quantity', 32)->nullable()->after('name');   // mis. "1 UNIT", "1 SET", "2 UNIT"
            $t->string('pic', 100)->nullable()->after('status');
            $t->string('location', 100)->nullable()->after('pic');
        });
    }

    public function down(): void
    {
        Schema::table('bs_fixed_assets', function (Blueprint $t): void {
            $t->dropColumn(['quantity', 'pic', 'location']);
        });
    }
};
