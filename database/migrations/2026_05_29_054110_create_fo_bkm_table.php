<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_bkm', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_bkm', 32);
            $table->date('tgl_bkm');
            $table->foreignId('tts_id')->nullable()->constrained('fo_tts')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('fo_customers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('kontrak_id')->nullable()->constrained('fo_kontrak')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->cascadeOnUpdate()->nullOnDelete();
            $table->decimal('amount', 18, 2)->default(0);
            $table->enum('jenis_pembayaran', ['BOOKING', 'DP', 'ANGSURAN', 'PELUNASAN', 'LAIN'])->default('ANGSURAN');
            $table->string('akun_kas', 32)->nullable();
            $table->string('no_referensi', 64)->nullable();
            $table->enum('status', ['POSTED', 'BATAL'])->default('POSTED');
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_bkm']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'tgl_bkm']);
        });

        // Now that fo_bkm exists, link fo_tts.bkm_id FK
        Schema::table('fo_tts', function (Blueprint $table) {
            $table->foreign('bkm_id')->references('id')->on('fo_bkm')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fo_tts', function (Blueprint $table) {
            $table->dropForeign(['bkm_id']);
        });
        Schema::dropIfExists('fo_bkm');
    }
};
