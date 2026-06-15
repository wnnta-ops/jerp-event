<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No. Pengajuan = identitas batch satu kali Generate dari checklist Pengajuan BLT.
 * Dipakai untuk mengelompokkan & mencetak ulang permohonan di Realisasi BLT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_blt', function (Blueprint $table) {
            $table->string('no_pengajuan')->nullable()->after('no_blt')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fo_blt', function (Blueprint $table) {
            $table->dropIndex(['no_pengajuan']);
            $table->dropColumn('no_pengajuan');
        });
    }
};
