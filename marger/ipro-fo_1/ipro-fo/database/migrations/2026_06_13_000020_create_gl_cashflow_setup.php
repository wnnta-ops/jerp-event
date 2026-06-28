<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GL Setup → Kategori Cash Flow (Direct & Indirect) + Mapping Cash Flow Direct.
 *  bs_cash_flow_categories : baris/kategori laporan arus kas (per metode + aktivitas).
 *  bs_cash_flow_mappings   : peta akun CoA → kategori (untuk metode Direct).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_cash_flow_categories')) {
            Schema::create('bs_cash_flow_categories', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained();
                $t->string('kind', 12);                 // direct | indirect
                $t->string('code', 16);
                $t->string('name', 120);
                $t->string('activity', 16)->nullable();  // operating | investing | financing
                $t->integer('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['tenant_id', 'kind', 'code']);
            });
        }

        if (! Schema::hasTable('bs_cash_flow_mappings')) {
            Schema::create('bs_cash_flow_mappings', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained();
                $t->unsignedBigInteger('account_id');    // BsAccount
                $t->unsignedBigInteger('category_id');   // bs_cash_flow_categories (kind=direct)
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['tenant_id', 'account_id']);
                $t->index('category_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cash_flow_mappings');
        Schema::dropIfExists('bs_cash_flow_categories');
    }
};
