<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nama bank FREE-TEXT untuk Bilyet Giro (ketik manual, tak wajib dari master bank).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_bg', function (Blueprint $t): void {
            $t->string('bank_nama')->nullable()->after('bank_id');
        });
    }

    public function down(): void
    {
        Schema::table('fo_bg', function (Blueprint $t): void {
            $t->dropColumn('bank_nama');
        });
    }
};
