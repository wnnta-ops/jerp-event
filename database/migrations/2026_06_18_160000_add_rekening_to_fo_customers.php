<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekening customer (untuk tujuan refund/pengembalian). Diisi saat pendaftaran /
 * master customer; Refund otomatis mengikuti data ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_customers', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_id')->nullable()->after('email');
            $table->string('no_rekening', 64)->nullable()->after('bank_id');
            $table->string('atas_nama_rekening')->nullable()->after('no_rekening');
        });
    }

    public function down(): void
    {
        Schema::table('fo_customers', function (Blueprint $table) {
            $table->dropColumn(['bank_id', 'no_rekening', 'atas_nama_rekening']);
        });
    }
};
