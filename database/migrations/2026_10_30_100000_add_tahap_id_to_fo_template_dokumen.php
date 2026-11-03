<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template Dokumen SPR/SPRS bisa khusus satu TAHAP dalam cluster (master fo_tahap).
 * NULL = semua tahap — versi yang sudah ada tetap berlaku seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fo_template_dokumen', 'tahap_id')) {
            return;
        }
        Schema::table('fo_template_dokumen', function (Blueprint $t) {
            $t->unsignedBigInteger('tahap_id')->nullable()->after('lokasi_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fo_template_dokumen', function (Blueprint $t) {
            $t->dropColumn('tahap_id');
        });
    }
};
