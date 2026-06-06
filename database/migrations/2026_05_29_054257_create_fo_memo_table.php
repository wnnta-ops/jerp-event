<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_memo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_memo', 32);
            $table->date('tgl_memo');
            $table->foreignId('customer_id')->constrained('fo_customers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('kontrak_id')->nullable()->constrained('fo_kontrak')->cascadeOnUpdate()->nullOnDelete();
            $table->enum('jenis', ['CASHBACK', 'ADJUSTMENT', 'KOREKSI', 'DISKON', 'LAIN'])->default('CASHBACK');
            $table->enum('arah', ['DEBIT', 'KREDIT'])->default('KREDIT');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('akun_pasangan', 32)->nullable();
            $table->enum('status', ['DRAFT', 'POSTED', 'BATAL'])->default('DRAFT');
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_memo']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_memo');
    }
};
