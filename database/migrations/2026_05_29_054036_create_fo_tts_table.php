<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_tts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_tts', 32);
            $table->date('tgl_tts');
            $table->date('tgl_terima')->nullable();
            $table->foreignId('customer_id')->constrained('fo_customers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('kontrak_id')->nullable()->constrained('fo_kontrak')->cascadeOnUpdate()->nullOnDelete();
            $table->enum('jenis_pembayaran', ['BOOKING', 'DP', 'ANGSURAN', 'PELUNASAN', 'LAIN'])->default('ANGSURAN');
            $table->decimal('amount', 18, 2)->default(0);
            $table->enum('cara_bayar', ['CASH', 'TRANSFER', 'GIRO', 'CHEQUE', 'VA', 'XENDIT'])->default('TRANSFER');
            $table->foreignId('bank_id')->nullable()->constrained('banks')->cascadeOnUpdate()->nullOnDelete();
            $table->string('no_referensi', 64)->nullable();
            $table->date('tgl_efektif')->nullable();
            $table->enum('status', ['DRAFT', 'KONFIRMASI', 'JADI_BKM', 'JADI_BGM', 'BATAL'])->default('DRAFT');
            $table->foreignId('bkm_id')->nullable();
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_tts']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'tgl_tts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_tts');
    }
};
