<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Set Up Debit Card (Cash Book → Setup) — kartu debit/VA beserta saldonya.
 *
 * SALDONYA TIDAK DIUBAH LANGSUNG. Setiap penambahan & pengurangan lewat PERMINTAAN yang harus
 * disetujui dulu (bs_credit_card_requests). Saldo yang bisa disunting bebas tidak punya jejak
 * siapa menambah berapa dan kapan — sementara angka itulah yang menentukan sebuah kartu boleh
 * dipakai membayar atau tidak.
 *
 * MASA BERLAKU juga lewat permintaan. Bon sementara berpembayaran Debit VA menentukan tanggal
 * expired-nya, dan tanggal itu menjadi permintaan tersendiri: yang berhak memutuskan sampai
 * kapan sebuah kartu boleh dipakai bukan si pengaju bon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_credit_cards', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            // Akun GL kartunya (mis. 10101020210 MANDIRI REK. 141-00-9988886-9). Jurnal
            // pembayaran Debit VA mendebit akun INI, bukan Uang Muka Operasional.
            $t->unsignedBigInteger('account_id');
            $t->string('card_number', 40)->nullable();
            $t->string('name', 150);
            // Kartu baru TIDAK langsung aktif: harus diaktifkan & diisi saldonya dulu.
            $t->boolean('is_active')->default(false);
            $t->decimal('balance', 20, 2)->default(0);
            // Diisi dari permintaan expired yang disetujui. Lewat tanggal ini saldonya tidak
            // bisa dipakai lagi.
            $t->date('expired_date')->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['tenant_id', 'account_id']);
            $t->foreign('account_id')->references('id')->on('bs_accounts')->restrictOnDelete();
        });

        Schema::create('bs_credit_card_requests', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('credit_card_id');
            // topup  : menambah saldo
            // reduce : mengurangi saldo
            // expiry : menetapkan masa berlaku kartu
            $t->string('type', 16);
            $t->decimal('amount', 20, 2)->nullable();
            $t->date('expired_date')->nullable();
            $t->string('status', 16)->default('pending');
            // Asal permintaannya — 'cash_advance' + id bon, atau NULL bila diajukan manual dari
            // layar Set Up Debit Card. Tanpa ini, permintaan yang lahir otomatis dari bon tidak
            // bisa ditelusuri balik ke bon mana.
            $t->string('source_type', 32)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('reference', 80)->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->index(['source_type', 'source_id']);
            $t->foreign('credit_card_id')->references('id')->on('bs_credit_cards')->cascadeOnDelete();
        });

        // Kartu MANDIRI REK. 141-00-9988886-9 disiapkan karena akun itulah yang dipakai jurnal
        // pembayaran Debit VA. Dibuat NONAKTIF & bersaldo 0: harus diaktifkan dan diisi
        // saldonya dulu, sama seperti kartu lain — bukan siap pakai tanpa ada yang memutuskan.
        $akun = DB::table('bs_accounts')->where('code', '10101020210')->first(['id', 'name']);
        $tenant = DB::table('tenants')->orderBy('id')->value('id');
        if ($akun && $tenant) {
            DB::table('bs_credit_cards')->insertOrIgnore([
                'tenant_id'   => $tenant,
                'account_id'  => $akun->id,
                'card_number' => '141-00-9988886-9',
                'name'        => $akun->name,
                'is_active'   => false,
                'balance'     => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_credit_card_requests');
        Schema::dropIfExists('bs_credit_cards');
    }
};
