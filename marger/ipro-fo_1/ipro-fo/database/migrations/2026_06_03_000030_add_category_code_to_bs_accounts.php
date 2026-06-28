<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori akun (eProperty: Kategori Account) — pengelompokan head CoA yang BISA
 * berbeda dari awalan kode. Contoh: kode 502/503 (BEBAN PENJUALAN & UMUM) berada
 * di Kategori 6 "BEBAN", sedangkan 501 (BEBAN POKOK) di Kategori 5 "BEBAN".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_accounts', function (Blueprint $t): void {
            $t->string('category_code', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bs_accounts', function (Blueprint $t): void {
            $t->dropColumn('category_code');
        });
    }
};
