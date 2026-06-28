<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUSINESS SUPPORT — Booked Revenue (pendapatan ter-booking per customer & unit).
 *
 * Booking : Dr Piutang Usaha                 / Cr Pendapatan Diterima Dimuka (Uang Muka Penjualan)
 * Akui    : Dr Pendapatan Diterima Dimuka    / Cr Penjualan Usaha
 * Batal   : Dr Pendapatan Diterima Dimuka    / Cr Piutang Usaha (membalik jurnal booking)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_booked_revenues', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('br_number', 48)->unique();
            $t->date('booking_date');
            $t->unsignedBigInteger('customer_id');             // dari modul Customer
            $t->unsignedBigInteger('unit_id');                 // dari modul Unit
            $t->unsignedBigInteger('project_id')->nullable();  // diturunkan dari unit (pelaporan)
            $t->decimal('amount', 20, 2);                      // nilai revenue ter-booking
            $t->unsignedBigInteger('receivable_account_id');   // Piutang Usaha
            $t->unsignedBigInteger('deferred_account_id');     // Pendapatan Diterima Dimuka
            $t->unsignedBigInteger('income_account_id');       // Penjualan Usaha (saat diakui)
            $t->unsignedBigInteger('cost_center_id')->nullable();
            $t->string('reference')->nullable();               // no. booking/kontrak terkait (opsional)
            $t->string('description')->nullable();
            $t->string('status', 20)->default('booked');       // booked | recognized | cancelled
            $t->unsignedBigInteger('booking_journal_id')->nullable();
            $t->timestamp('recognized_at')->nullable();
            $t->unsignedBigInteger('recognition_journal_id')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedBigInteger('cancel_journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['tenant_id', 'status']);
            $t->index('customer_id');
            $t->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_booked_revenues');
    }
};
