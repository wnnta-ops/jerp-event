<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #55 — Arus keluar BG/Cek perusahaan (BG/Cek yang diterbitkan ke supplier/vendor).
 * Register + pelacakan siklus: dimiliki (available) → beredar (issued) → cair (cleared).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_outgoing_cheques', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('cheque_number', 80);                 // nomor BG/Cek
            $t->string('cheque_type', 8)->default('cek');    // cek / bg
            $t->unsignedBigInteger('bank_account_id');       // rekening bank perusahaan (penerbit)
            $t->string('payee')->nullable();                 // supplier/vendor penerima
            $t->decimal('amount', 20, 2);
            $t->date('cheque_date');                         // tanggal BG/Cek
            $t->date('handover_date')->nullable();           // diserahkan ke supplier/vendor
            $t->date('cleared_date')->nullable();            // dicairkan oleh supplier/vendor
            $t->string('signer_name')->nullable();           // pejabat penandatangan
            $t->string('description')->nullable();
            $t->string('status', 12)->default('available');  // available / issued / cleared
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['status', 'cheque_date']);
            $t->index('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_outgoing_cheques');
    }
};
