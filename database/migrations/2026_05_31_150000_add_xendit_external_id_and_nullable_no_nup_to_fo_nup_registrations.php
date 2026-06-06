<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            // Referensi pembayaran UTJ ke Xendit — kolom khusus, BUKAN no_nup.
            // No NUP baru terbit saat approval, jadi pembayaran (yang terjadi
            // sebelum approval) memakai external id tersendiri.
            $table->string('xendit_external_id', 64)->nullable()->unique()->after('xendit_invoice_id');

            // no_nup kini diterbitkan saat approval → nullable saat pendaftaran.
            // Unique (tenant_id, no_nup) tetap aman: NULL dianggap distinct.
            $table->string('no_nup', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropUnique(['xendit_external_id']);
            $table->dropColumn('xendit_external_id');
            $table->string('no_nup', 32)->nullable(false)->change();
        });
    }
};
