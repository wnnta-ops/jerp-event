<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Urutan NUP diterbitkan saat tim verifikasi meng-approve pendaftaran
 * (FIFO per event), bukan saat user submit form publik. Kolom jadi nullable.
 * (no_nup nullable di-handle migration terpisah di slot 2026_05_31_150000.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->unsignedInteger('urutan')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->unsignedInteger('urutan')->nullable(false)->change();
        });
    }
};
