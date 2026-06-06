<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_events', function (Blueprint $table) {
            // Daftar pilihan "Jenis Tipe NUP" yang tampil sebagai dropdown di form
            // pendaftaran publik. Dikonfigurasi per-event di Setup NUP (Filament).
            $table->json('tipe_nup_options')->nullable()->after('keterangan');
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_events', function (Blueprint $table) {
            $table->dropColumn('tipe_nup_options');
        });
    }
};
