<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antrian posting FO (J-ERP) → DITAMPUNG di Posting Invoice sebelum jadi hutang AP.
 * kind: refund (per jenis sumber SPR/SPRS/Transfer Anonim) | komisi | blt. J-ERP menulis
 * baris 'pending' (Refund "Proses & Kirim ke BO"; Realisasi Komisi/BLT "Posting BO");
 * BO menerbitkan invoice hutang dari sini (status → 'posted'). Belum diposting = belum
 * masuk Account Payable. (Nama tabel historis 'refund'; kini umum utk refund/komisi/blt.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_ap_refund_queue', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->default(1);
            $t->string('kind', 12)->default('refund');       // refund / komisi / blt
            $t->string('refund_no', 60);                     // no dokumen sumber (RF / BLTPENG / KOMPENG)
            $t->unsignedBigInteger('refund_id')->nullable(); // id Refund J-ERP (hanya kind=refund)
            $t->unsignedBigInteger('customer_id')->nullable(); // id customer BO (refund); null utk komisi/blt batch
            $t->string('customer_name')->nullable();
            $t->string('refund_source', 40)->nullable();     // refund: SPR/SPRS/TRANSFER_ANONIM; komisi/blt: null
            $t->decimal('amount', 20, 2)->default(0);
            // Id akun BO (di-resolve J-ERP via coa_map): payable (hutang) & lawan (beban/titipan).
            $t->unsignedBigInteger('payable_account_id')->nullable();
            $t->unsignedBigInteger('offset_account_id')->nullable();
            $t->date('tgl_refund')->nullable();
            $t->string('keterangan')->nullable();
            $t->string('status', 12)->default('pending');    // pending / posted
            $t->unsignedBigInteger('ap_invoice_id')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'kind', 'refund_no']);
            $t->index(['status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_ap_refund_queue');
    }
};
