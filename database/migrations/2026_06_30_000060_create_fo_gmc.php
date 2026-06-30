<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GMC (Gimmick / FREE SPR) — biaya promo yang ditanggung developer untuk customer
 * (mis. FREE AC, FREE biaya KPR). BUKAN tagihan customer (tak masuk Jadwal Tagihan SPR).
 * Section sendiri di Finance AR; dikirim ke Posting Invoice BO (Sumber: GMC) via tombol
 * "Posting BO" — sama pola Komisi/BLT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_gmc', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->default(1);
            $t->string('no_gmc')->nullable();
            $t->date('tgl')->nullable();
            $t->unsignedBigInteger('kontrak_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('nama');                          // nama gimmick (FREE AC, FREE KPR, dll)
            $t->decimal('nilai', 20, 2)->default(0);     // biaya gimmick (ditanggung developer)
            $t->string('keterangan')->nullable();
            $t->string('status', 20)->default('DRAFT');  // DRAFT / DIPROSES (sudah dikirim ke BO)
            $t->timestamps();
            $t->softDeletes();
            $t->index('kontrak_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_gmc');
    }
};
