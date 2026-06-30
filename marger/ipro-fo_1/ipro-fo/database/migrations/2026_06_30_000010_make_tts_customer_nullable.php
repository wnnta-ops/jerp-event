<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tts_receipts.customer_id → nullable. Diperlukan agar penerimaan TANPA UNIT /
 * anonim (Transfer Anonim FO yang belum teridentifikasi customer) bisa masuk
 * sebagai TTS. UI BO sudah aman untuk customer null ($t->customer?->full_name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_receipts', function (Blueprint $t): void {
            $t->dropForeign(['customer_id']);
        });
        Schema::table('tts_receipts', function (Blueprint $t): void {
            $t->foreignId('customer_id')->nullable()->change();
            $t->foreign('customer_id')->references('id')->on('customers');
        });
    }

    public function down(): void
    {
        Schema::table('tts_receipts', function (Blueprint $t): void {
            $t->dropForeign(['customer_id']);
        });
        Schema::table('tts_receipts', function (Blueprint $t): void {
            $t->foreignId('customer_id')->nullable(false)->change();
            $t->foreign('customer_id')->references('id')->on('customers');
        });
    }
};
