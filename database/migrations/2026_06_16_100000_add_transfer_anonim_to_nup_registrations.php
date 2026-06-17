<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bukti pembayaran booked = Transfer Anonim yang sudah diidentifikasi finance.
 * Verifikasi Data Booked harus dicocokkan ke transfer ini sebelum di-Approve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_anonim_id')->nullable()->after('tts_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropIndex(['transfer_anonim_id']);
            $table->dropColumn('transfer_anonim_id');
        });
    }
};
