<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #49 — Bon Sementara: jurnal Uang Muka Operasional baru terbentuk setelah dibayar Finance.
 * Saat pengajuan ("requested") belum ada jurnal & belum ada sumber kas; Finance mengisi
 * tanggal transfer + sumber bank/kas saat pembayaran → status jadi "outstanding".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            $t->unsignedBigInteger('cash_account_id')->nullable()->change(); // diisi Finance saat bayar
            $t->date('paid_date')->nullable();         // tanggal transfer Finance
        });
    }

    public function down(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            $t->dropColumn('paid_date');
        });
    }
};
