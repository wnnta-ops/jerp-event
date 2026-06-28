<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUSINESS SUPPORT — Fondasi bersama (dipakai semua sub-modul: AP, AR, Budget,
 * CashBook, COGS, Contractor, Inventory, Purchasing).
 *
 *  - bs_accounts      : Chart of Account / buku besar (General Ledger).
 *  - bs_cost_centers  : Cost Center / Divisi (untuk budget & alokasi biaya).
 *  - bs_suppliers     : Master Supplier / Vendor (Purchasing & AP).
 *
 * Tahap awal OPERASIONAL — jurnal/voucher GL otomatis menyusul.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Chart of Account (General Ledger) ──
        Schema::create('bs_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 32);                       // mis. 1-1100
            $t->string('name');                            // mis. Kas Besar
            $t->string('type', 20);                        // asset/liability/equity/income/expense
            $t->string('normal_balance', 6)->default('D'); // D / K
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->boolean('is_postable')->default(true);     // boleh dijurnal (akun detail) atau header
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
            $t->index(['tenant_id', 'type']);
        });

        // ── Cost Center / Divisi ──
        Schema::create('bs_cost_centers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->string('code', 32);
            $t->string('name');
            $t->string('manager')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
        });

        // ── Supplier / Vendor ──
        Schema::create('bs_suppliers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->string('category', 64)->nullable();      // material/jasa/kontraktor/dll
            $t->string('contact_person')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('address', 255)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('npwp', 32)->nullable();
            $t->string('bank_name', 64)->nullable();
            $t->string('bank_account_no', 64)->nullable();
            $t->string('bank_account_name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_suppliers');
        Schema::dropIfExists('bs_cost_centers');
        Schema::dropIfExists('bs_accounts');
    }
};
