<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memo Urgent menyebut supplier-nya (wajib di form; kolom nullable untuk memo lama). Saat memo
 * ditarik ke Registrasi Invoice Hutang, supplier invoice terisi dari memo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memo_internals', function (Blueprint $table): void {
            if (! Schema::hasColumn('memo_internals', 'supplier_id')) {
                $table->unsignedBigInteger('supplier_id')->nullable()->index()->after('department');
            }
        });
    }

    public function down(): void
    {
        Schema::table('memo_internals', function (Blueprint $table): void {
            if (Schema::hasColumn('memo_internals', 'supplier_id')) {
                $table->dropColumn('supplier_id');
            }
        });
    }
};
