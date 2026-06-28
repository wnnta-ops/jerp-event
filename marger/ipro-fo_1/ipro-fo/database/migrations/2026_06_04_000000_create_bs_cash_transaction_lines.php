<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #15 — Cash Book multi-akun.
 * Satu transaksi kas masuk/keluar boleh dipecah ke >1 akun lawan (split transaction).
 * Sisi kas tetap 1 rekening; rincian akun lawan disimpan per-baris di sini.
 * Baris lama (counter_account_id tunggal) di-backfill jadi 1 baris agar seragam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_cash_transaction_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('cash_transaction_id');
            $t->unsignedBigInteger('account_id');               // akun lawan (beban/pendapatan/dll)
            $t->decimal('amount', 20, 2);
            $t->string('description')->nullable();              // catatan/peruntukan per-baris
            $t->timestamps();

            $t->index('cash_transaction_id');
            $t->index('account_id');
            $t->foreign('cash_transaction_id')->references('id')->on('bs_cash_transactions')->cascadeOnDelete();
        });

        // Backfill: tiap transaksi lama → 1 baris (akun lawan + nominal penuh).
        foreach (DB::table('bs_cash_transactions')->whereNull('deleted_at')->get() as $trx) {
            DB::table('bs_cash_transaction_lines')->insert([
                'tenant_id'           => $trx->tenant_id,
                'cash_transaction_id' => $trx->id,
                'account_id'          => $trx->counter_account_id,
                'amount'              => $trx->amount,
                'description'         => $trx->description,
                'created_at'          => $trx->created_at,
                'updated_at'          => $trx->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cash_transaction_lines');
    }
};
