<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COA (akun GL) yang di-link-kan pada tiap ketentuan retensi bank.
 *
 * Set Up KPR → Kategori Bank → tab Retensi kini bisa menentukan akun Bank mana yang dipakai
 * per kategori (mis. BTN · TANAH MATANG 30% → 10101020204 BCA REK. 6155428914). Nilai ini
 * menjadi DEFAULT akun Debit pada Approval Retensi/Pencairan, menggantikan tebakan berdasarkan
 * nama/nomor rekening yang selama ini dipakai (mapBankAccountToGl()).
 *
 * nullOnDelete: menghapus akun GL cukup melepas tautannya — ketentuan retensinya tetap utuh
 * dan Approval Retensi kembali ke tebakan lama, bukan kehilangan baris ketentuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_retention_terms', function (Blueprint $t): void {
            $t->foreignId('gl_account_id')->nullable()->after('retention_category_id')
                ->constrained('bs_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_retention_terms', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('gl_account_id');
        });
    }
};
