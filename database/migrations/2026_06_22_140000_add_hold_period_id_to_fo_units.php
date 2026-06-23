<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kaitkan hold unit ke periode Setup Hold. Auto-release mengikuti tgl_selesai
 * periode (bukan snapshot) → hold selalu sesuai tanggal yang di-setup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->unsignedBigInteger('hold_period_id')->nullable()->after('hold_by');
        });
    }

    public function down(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->dropColumn('hold_period_id');
        });
    }
};
