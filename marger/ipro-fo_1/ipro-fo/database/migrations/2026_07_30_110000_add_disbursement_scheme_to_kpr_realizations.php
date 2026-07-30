<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jalur pencairan KPR: BSN atau SELAIN BSN.
 *
 * Wajib dipilih pada form "Catat Pencairan" (satuan). NULLABLE karena baris pencairan lama
 * belum punya nilai ini, dan jalur Pencairan Massal tidak memintanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_realizations', function (Blueprint $table) {
            $table->string('disbursement_scheme', 16)->nullable()->after('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('kpr_realizations', function (Blueprint $table) {
            $table->dropColumn('disbursement_scheme');
        });
    }
};
