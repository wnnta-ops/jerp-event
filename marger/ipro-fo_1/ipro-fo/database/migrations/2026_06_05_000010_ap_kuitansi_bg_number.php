<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #50 — Kuitansi Hutang: nomor BG/Cek (muncul saat alat transaksi = Cek/Giro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_kuitansis', function (Blueprint $t): void {
            $t->string('bg_number', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_kuitansis', function (Blueprint $t): void {
            $t->dropColumn('bg_number');
        });
    }
};
