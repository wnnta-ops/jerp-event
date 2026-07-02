<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Kategori Gimmick — fungsi SAMA dengan Kategori Invoice (bs_ap_categories):
 * kategori → akun debit + akun kredit (hutang), untuk pemetaan GL biaya gimmick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_gimmick_categories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name');
            $t->unsignedBigInteger('debit_account_id');     // Acc. Link / Debit
            $t->unsignedBigInteger('payable_account_id');   // Acc. Kredit (hutang)
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_gimmick_categories');
    }
};
