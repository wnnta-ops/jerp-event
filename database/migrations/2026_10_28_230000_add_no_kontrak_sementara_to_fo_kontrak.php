<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor SPR sementara J-ERP — selama input masih di dua tempat, nomor resmi SPR diterbitkan
 * eProperty. SPR yang dibuat di J-ERP lalu ditemukan sama dengan SPR eProperty pada migrasi FO
 * harian (eprop:sinkron) memakai nomor eProperty; nomor J-ERP sebelumnya disimpan di sini agar
 * tetap bisa dicari. Diisi oleh sinkron eProperty, bukan oleh layar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fo_kontrak', 'no_kontrak_sementara')) {
            return;
        }
        Schema::table('fo_kontrak', function (Blueprint $t) {
            $t->string('no_kontrak_sementara', 32)->nullable()->after('no_kontrak')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $t) {
            $t->dropColumn('no_kontrak_sementara');
        });
    }
};
