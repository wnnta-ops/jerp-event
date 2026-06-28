<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #98 — SIP (Standing Instruction Payment): dokumen instruksi + persetujuan pembayaran
 *  atas Invoice Hutang (AP) & Bon Sementara yang sudah diajukan, dikelompokkan per BANK SUMBER.
 *  Tidak memposting jurnal (pembayaran tetap lewat Kuitansi Hutang / Bayar Bon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_sips', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('sip_number', 48)->unique();
            $t->date('sip_date');
            $t->string('title')->nullable();
            $t->unsignedBigInteger('cash_account_id');             // bank SUMBER pembayaran (BsAccount)
            $t->string('description')->nullable();
            $t->decimal('total', 20, 2)->default(0);
            $t->string('status', 16)->default('submitted');        // submitted / approved / printed / cancelled
            $t->json('approvals')->nullable();                     // {slot: {name, approved_by, approved_at}}
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['tenant_id', 'sip_date']);
        });

        Schema::create('bs_sip_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sip_id')->constrained('bs_sips')->cascadeOnDelete();
            $t->string('sourceable_type');                         // BsApInvoice | BsCashAdvance (FQCN)
            $t->unsignedBigInteger('sourceable_id');
            $t->string('payee_type', 12);                          // supplier / employee
            $t->string('payee_name');                              // nama supplier / pemegang bon
            $t->string('dest_bank_name')->nullable();              // bank tujuan pembayaran
            $t->string('dest_account_no')->nullable();             // no rekening tujuan
            $t->string('dest_account_name')->nullable();
            $t->string('description')->nullable();                 // keterangan pembayaran
            $t->decimal('amount', 20, 2);
            $t->index(['sourceable_type', 'sourceable_id']);
            $t->index('sip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_sip_lines');
        Schema::dropIfExists('bs_sips');
    }
};
