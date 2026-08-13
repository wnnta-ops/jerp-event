<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bank Pilihan pendaftar (wajib saat Cara Bayar = KPR). bank_nama disimpan
        // sebagai snapshot — sama seperti ref_sales_name/tipe_nup — agar nama bank
        // di data lama tidak ikut berubah bila master Setup Bank diedit/dihapus.
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->foreignId('bank_id')->nullable()->after('cara_bayar')
                ->constrained('fo_banks')->cascadeOnUpdate()->nullOnDelete();
            $table->string('bank_nama', 100)->nullable()->after('bank_id');
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropColumn('bank_nama');
            $table->dropConstrainedForeignId('bank_id');
        });
    }
};
