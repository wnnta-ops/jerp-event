<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GL Setup → Multi Currency + Kurs Sistem.
 *  bs_currencies      : master mata uang (kode ISO, nama, simbol).
 *  bs_exchange_rates  : kurs sistem per tanggal (1 unit mata uang = rate × IDR).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_currencies')) {
            Schema::create('bs_currencies', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained();
                $t->string('code', 8);               // IDR, USD, SGD …
                $t->string('name', 80);
                $t->string('symbol', 8)->nullable();  // Rp, $, S$ …
                $t->boolean('is_base')->default(false); // mata uang dasar (IDR)
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('bs_exchange_rates')) {
            Schema::create('bs_exchange_rates', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained();
                $t->string('currency_code', 8);
                $t->date('rate_date');
                $t->decimal('rate', 18, 6)->default(1); // 1 unit mata uang = rate × IDR
                $t->string('note')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['tenant_id', 'currency_code', 'rate_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_exchange_rates');
        Schema::dropIfExists('bs_currencies');
    }
};
