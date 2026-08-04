<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Angka manual per periode pada Report Cashflow — bukan hasil transaksi, jadi diinput operator:
 *  - blokir_angsuran : dana yang diblokir untuk angsuran loan (mengurangi Effective Balance)
 *  - buffer          : kas minimum yang disisihkan (mengurangi Nett Effective Balance)
 *
 * Baris Beginning/Ending Balance TIDAK disimpan: keduanya dihitung dari rumus (lihat
 * CashFlowPlanController::report()) supaya tidak pernah berbeda dengan angka transaksinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_cf_period_settings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedSmallInteger('period_year');
            $t->unsignedTinyInteger('period_month');
            $t->decimal('blokir_angsuran', 20, 2)->default(0);
            $t->decimal('buffer', 20, 2)->default(0);
            $t->timestamps();
            $t->unique(['tenant_id', 'period_year', 'period_month'], 'bs_cf_period_settings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cf_period_settings');
    }
};
