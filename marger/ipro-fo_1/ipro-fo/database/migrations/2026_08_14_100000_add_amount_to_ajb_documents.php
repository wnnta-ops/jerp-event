<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nominal AJB (nilai transaksi pada akta) — dipakai kolom "Nominal AJB" pada Detail AJB.
 *
 * Nullable: dokumen AJB lama tidak punya nominal, dan justru ketiadaannya yang membuat baris
 * berketerangan "BELUM BAST" pada Detail AJB, jadi tidak boleh diisi tebakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ajb_documents', function (Blueprint $t): void {
            if (! Schema::hasColumn('ajb_documents', 'amount')) {
                $t->decimal('amount', 20, 2)->nullable()->after('signed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ajb_documents', function (Blueprint $t): void {
            $t->dropColumn('amount');
        });
    }
};
