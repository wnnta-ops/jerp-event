<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice Piutang: kaitan ke SPR (booking).
 *
 * Sebelumnya bs_ar_invoices HANYA terhubung ke customer — tidak ada jalan untuk tahu invoice ini
 * menagih unit/SPR yang mana. Akibatnya rincian piutang tidak bisa disandingkan dengan Booked
 * Revenue, dan Rekonsiliasi Piutang tidak bisa dikelompokkan per SPR (1 customer bisa punya
 * beberapa unit).
 *
 * SPR = bookings.booking_number (tidak ada tabel/kolom bernama "spr" di aplikasi ini). Unit tidak
 * disimpan terpisah karena sudah pasti turunan booking (bookings.unit_id) — menyimpannya dua kali
 * hanya membuka peluang keduanya berbeda.
 *
 * Nullable: invoice non-unit (mis. denda, saldo awal, tagihan jasa) memang tidak punya SPR, dan
 * seluruh invoice lama otomatis NULL. Layar Rekonsiliasi mengelompokkan yang NULL sebagai
 * "Tanpa SPR" alih-alih menyembunyikannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_ar_invoices', 'booking_id')) {
            return;
        }
        Schema::table('bs_ar_invoices', function (Blueprint $t): void {
            $t->foreignId('booking_id')->nullable()->after('customer_id')
                ->constrained('bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_ar_invoices', 'booking_id')) {
            return;
        }
        Schema::table('bs_ar_invoices', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('booking_id');
        });
    }
};
