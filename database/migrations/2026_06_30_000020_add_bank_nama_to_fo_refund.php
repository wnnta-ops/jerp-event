<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nama bank tujuan FREE-TEXT untuk Refund (saat bank tidak ada di master → pilih
 * "Lain-lain" lalu ketik). bank_id null + bank_nama terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_refund', function (Blueprint $t): void {
            $t->string('bank_nama')->nullable()->after('bank_id');
        });
    }

    public function down(): void
    {
        Schema::table('fo_refund', function (Blueprint $t): void {
            $t->dropColumn('bank_nama');
        });
    }
};
