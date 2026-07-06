<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_transfer_anonim', function (Blueprint $table) {
            // Bank penerima = akun bank di Chart of Account BO (ipro_bo.bs_accounts.code).
            // Menggantikan pilihan institusi bank (bank_id) untuk Transfer Anonim.
            $table->string('coa_bank_code', 30)->nullable()->after('bank_id');
        });
    }

    public function down(): void
    {
        Schema::table('fo_transfer_anonim', function (Blueprint $table) {
            $table->dropColumn('coa_bank_code');
        });
    }
};
