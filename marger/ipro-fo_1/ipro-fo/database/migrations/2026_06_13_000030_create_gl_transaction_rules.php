<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GL Setup → Transaction Rule: pemetaan transaksi sumber (AP/AR/Kas/Persediaan/Memorial)
 *  ke template jurnal otomatis (baris debit/kredit + peran akun + basis nilai).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_transaction_rules')) {
            Schema::create('bs_transaction_rules', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained();
                $t->string('code', 32);
                $t->string('name', 150);
                $t->string('source', 16);                // ar | ap | cash | inventory | memorial
                $t->string('event', 40)->nullable();     // invoice | payment | receipt | depreciation …
                $t->string('description')->nullable();
                $t->integer('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['tenant_id', 'code']);
                $t->index(['tenant_id', 'source']);
            });
        }

        if (! Schema::hasTable('bs_transaction_rule_lines')) {
            Schema::create('bs_transaction_rule_lines', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('rule_id')->constrained('bs_transaction_rules')->cascadeOnDelete();
                $t->integer('sort_order')->default(0);
                $t->string('side', 6);                   // debit | credit
                $t->string('account_role', 200);         // peran akun (deskriptif)
                $t->unsignedBigInteger('account_id')->nullable(); // akun default (BsAccount) bila dipatok
                $t->string('amount_basis', 24)->default('total'); // total | dpp | ppn | pph | net | nominal | qty_cost
                $t->text('description')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_transaction_rule_lines');
        Schema::dropIfExists('bs_transaction_rules');
    }
};
