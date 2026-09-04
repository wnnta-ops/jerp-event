<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kartu kredit/VA yang dipakai sebuah bon sementara berpembayaran Debit VA.
 *
 * Nullable: pembayaran reguler tidak memakai kartu, dan seluruh bon yang sudah dibayar sebelum
 * kolom ini ada memang tidak punya kartunya. Kewajibannya ditegakkan saat pembayaran dicatat
 * (hanya untuk debit_va), bukan oleh skema — NOT NULL akan menolak setiap pembayaran reguler.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_cash_advances', 'credit_card_id')) {
            return;
        }

        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            $t->unsignedBigInteger('credit_card_id')->nullable()->after('va_expired_date');
            $t->foreign('credit_card_id')->references('id')->on('bs_credit_cards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_cash_advances', 'credit_card_id')) {
            return;
        }

        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            $t->dropForeign(['credit_card_id']);
            $t->dropColumn('credit_card_id');
        });
    }
};
