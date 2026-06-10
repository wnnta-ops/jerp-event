<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alamat surat menyurat lengkap (kecamatan + kelurahan) di master Customer,
 * agar dropdown wilayah berjenjang di /nup/daftar & /booked/daftar tersimpan
 * penuh (Provinsi → Kabupaten → Kecamatan → Kelurahan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_customers', function (Blueprint $table) {
            $table->string('mail_kecamatan')->nullable()->after('mail_city');
            $table->string('mail_kelurahan')->nullable()->after('mail_kecamatan');
        });
    }

    public function down(): void
    {
        Schema::table('fo_customers', function (Blueprint $table) {
            $table->dropColumn(['mail_kecamatan', 'mail_kelurahan']);
        });
    }
};
