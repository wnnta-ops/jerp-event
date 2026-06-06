<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_cb_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->enum('jenis', ['BKM', 'BKK', 'BGM', 'BGK', 'BNG', 'BS', 'ADM', 'ANO', 'PBS']);
            $table->string('no_voucher', 32);
            $table->date('tgl');
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->cascadeOnUpdate()->nullOnDelete();
            $table->string('akun_kas', 32);              // COA kode kas/bank
            $table->string('akun_lawan', 32);            // COA kode lawan (pendapatan/beban/hutang/piutang)
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->cascadeOnUpdate()->nullOnDelete();
            $table->decimal('nominal', 18, 2)->default(0);
            $table->string('pihak')->nullable();         // pembayar / penerima
            $table->string('no_referensi', 64)->nullable();
            $table->enum('status', ['DRAFT', 'POSTED', 'BATAL'])->default('DRAFT');
            $table->foreignId('jurnal_id')->nullable()->constrained('bo_jurnal_header')->nullOnDelete();
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_voucher']);
            $table->index(['tenant_id', 'jenis', 'status']);
            $table->index(['tenant_id', 'tgl']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_cb_vouchers');
    }
};
