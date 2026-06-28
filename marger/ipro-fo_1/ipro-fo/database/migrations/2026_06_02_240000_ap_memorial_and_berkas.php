<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AP sub-modul minor: Memorial Hutang (penyesuaian non-tunai + penghapusan/write-off)
 * & Kelengkapan Berkas (master checklist + per-invoice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_ap_memorials', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('memorial_number', 60)->unique();
            $t->date('memorial_date');
            $t->unsignedBigInteger('supplier_id');
            $t->unsignedBigInteger('ap_invoice_id')->nullable();
            $t->string('trx_type', 16);                       // penambahan / penghapusan
            $t->unsignedBigInteger('counter_account_id');     // akun lawan
            $t->unsignedBigInteger('payable_account_id');     // hutang
            $t->decimal('amount', 20, 2);
            $t->string('description');
            $t->string('kasir')->nullable();
            $t->string('manual_no', 80)->nullable();
            $t->boolean('is_writeoff')->default(false);       // dari modul Penghapusan Nilai Hutang
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_ap_berkas', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name');
            $t->boolean('is_mandatory')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('bs_ap_invoice_berkas', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ap_invoice_id')->constrained('bs_ap_invoices')->cascadeOnDelete();
            $t->unsignedBigInteger('berkas_id');
            $t->boolean('is_complete')->default(false);
            $t->unique(['ap_invoice_id', 'berkas_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_ap_invoice_berkas');
        Schema::dropIfExists('bs_ap_berkas');
        Schema::dropIfExists('bs_ap_memorials');
    }
};
