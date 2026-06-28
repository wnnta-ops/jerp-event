<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah field data form NUP lengkap + tracking pembayaran (Xendit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nup_registrations', function (Blueprint $table): void {
            $table->json('form_data')->nullable();                 // data form lengkap (preferensi unit, pasangan, sales, dll)
            $table->string('payment_status', 20)->default('unpaid'); // unpaid/pending/paid/expired/failed
            $table->string('payment_provider', 20)->nullable(); // xendit/simulation/manual
            $table->string('payment_ref')->nullable();    // Xendit invoice id
            $table->text('payment_url')->nullable();           // URL halaman bayar
            $table->string('payment_external_id')->nullable(); // external_id yang dikirim ke Xendit
        });
    }

    public function down(): void
    {
        Schema::table('nup_registrations', function (Blueprint $table): void {
            $table->dropColumn(['form_data', 'payment_status', 'payment_provider', 'payment_ref', 'payment_url', 'payment_external_id']);
        });
    }
};
