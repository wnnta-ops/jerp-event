<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice Piutang: kaitan 1:1 ke Booked Revenue.
 *
 * Sejak invoice piutang tidak lagi dibuat manual, setiap invoice lahir dari satu record
 * Booked Revenue (satu unit di-booking). Kolom ini menautkannya secara eksplisit, sekaligus
 * menjadi kunci idempotensi: satu Booked Revenue hanya boleh punya satu invoice — mencegah
 * invoice ganda bila proses booking terulang.
 *
 * Nullable + unique: invoice LAMA (dibuat manual sebelum perubahan ini) bernilai NULL dan
 * tidak terpengaruh; unique index memakai kolom nullable sehingga banyak NULL tetap diizinkan
 * MySQL, tetapi tiap booked_revenue_id yang terisi hanya boleh muncul sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_ar_invoices', 'booked_revenue_id')) {
            return;
        }
        Schema::table('bs_ar_invoices', function (Blueprint $t): void {
            $t->foreignId('booked_revenue_id')->nullable()->after('booking_id')
                ->constrained('bs_booked_revenues')->nullOnDelete();
            $t->unique('booked_revenue_id', 'bs_ar_inv_booked_rev_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_ar_invoices', 'booked_revenue_id')) {
            return;
        }
        Schema::table('bs_ar_invoices', function (Blueprint $t): void {
            $t->dropUnique('bs_ar_inv_booked_rev_unique');
            $t->dropConstrainedForeignId('booked_revenue_id');
        });
    }
};
