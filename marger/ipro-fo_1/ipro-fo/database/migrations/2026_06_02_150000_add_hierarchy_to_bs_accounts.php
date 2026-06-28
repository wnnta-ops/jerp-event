<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah hierarki Chart of Account ala eProperty (6 level):
 *  1 Kategori · 2 Sub Kategori · 3 Ledger · 4 Detil · 5 Detil Transaksi · 6 Detil Sub Transaksi (postable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_accounts', function (Blueprint $t): void {
            $t->unsignedTinyInteger('level')->default(6);
            $t->string('parent_code', 24)->nullable();
            $t->index('parent_code');
        });
    }

    public function down(): void
    {
        Schema::table('bs_accounts', function (Blueprint $t): void {
            $t->dropIndex(['parent_code']);
            $t->dropColumn(['level', 'parent_code']);
        });
    }
};
