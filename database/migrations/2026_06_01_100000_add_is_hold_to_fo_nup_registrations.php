<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Hold Aktivasi" — operator dapat menahan sementara aktivasi customer di
 * meja reception pada hari-H launching (mis. data perlu klarifikasi). Bisa
 * di-unhold kapan saja dari layar Aktivasi NUP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->boolean('is_hold')->default(false)->after('verif_status');
            $table->text('hold_reason')->nullable()->after('is_hold');
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropColumn(['is_hold', 'hold_reason']);
        });
    }
};
