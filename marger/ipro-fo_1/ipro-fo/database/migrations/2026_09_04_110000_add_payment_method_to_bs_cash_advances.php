<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cara pembayaran bon sementara — dipilih Finance saat mencatat pembayaran.
 *
 *   reguler   : transfer/tunai seperti selama ini.
 *   debit_va  : lewat Virtual Account, dan VA itu punya masa berlaku — tanggal expired-nya
 *               WAJIB dicatat, sebab VA yang lewat tanggalnya tidak bisa lagi didebit dan
 *               uangnya tidak pernah berpindah meski jurnalnya sudah terbentuk.
 *
 * BAWAANNYA 'reguler' — sama dengan satu-satunya cara yang ada selama ini, sehingga seluruh
 * bon yang sudah dibayar tetap terbaca sebagaimana adanya. va_expired_date NULLABLE karena
 * hanya bermakna pada debit VA; kewajibannya ditegakkan saat pembayaran dicatat, bukan lewat
 * NOT NULL yang akan menolak seluruh pembayaran reguler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_cash_advances', 'payment_method')) {
                $t->string('payment_method', 16)->default('reguler')->after('cash_account_id');
            }
            if (! Schema::hasColumn('bs_cash_advances', 'va_expired_date')) {
                $t->date('va_expired_date')->nullable()->after('payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            foreach (['va_expired_date', 'payment_method'] as $kolom) {
                if (Schema::hasColumn('bs_cash_advances', $kolom)) {
                    $t->dropColumn($kolom);
                }
            }
        });
    }
};
