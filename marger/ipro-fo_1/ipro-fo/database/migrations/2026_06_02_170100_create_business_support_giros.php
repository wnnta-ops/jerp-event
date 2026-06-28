<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CASH BOOK — Cek/Giro Masuk (incoming cheque).
 * Penerimaan → Kliring (cair ke bank) atau Tolakan (bounce, kembali ke piutang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_giros', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('giro_number', 48)->unique();            // nomor internal
            $t->date('receive_date');
            $t->date('due_date');                               // tanggal jatuh tempo cek/giro
            $t->string('bank_name');                            // bank penerbit
            $t->string('cheque_number')->nullable();            // no. warkat
            $t->string('drawer');                               // pihak penerbit (customer)
            $t->decimal('amount', 20, 2);
            $t->unsignedBigInteger('giro_account_id');          // Piutang Giro (holding)
            $t->unsignedBigInteger('counter_account_id');       // Piutang Usaha (lawan saat terima)
            $t->unsignedBigInteger('cash_account_id')->nullable();// bank tujuan saat kliring
            $t->string('status', 12)->default('in_hand');       // in_hand / cleared / bounced
            $t->date('settled_date')->nullable();
            $t->unsignedBigInteger('receive_journal_id')->nullable();
            $t->unsignedBigInteger('settle_journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_giros');
    }
};
