<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_ar_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_invoice', 32);
            $table->enum('jenis', ['FI', 'FK', 'FP'])->default('FI'); // Faktur Invoice/Komersil/Pajak
            $table->date('tgl');
            $table->date('jatuh_tempo');
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            // Mirror of FO customer (loose coupling — no FK to fo_customers).
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_nama')->nullable();
            $table->string('kontrak_ref', 64)->nullable();
            $table->string('akun_piutang', 32)->default('1.1.02.001');
            $table->string('akun_pendapatan', 32)->default('4.1.01.001');
            $table->decimal('nominal', 18, 2)->default(0);
            $table->decimal('denda', 18, 2)->default(0);
            $table->decimal('nominal_bayar', 18, 2)->default(0);
            $table->decimal('sisa', 18, 2)->default(0);
            $table->enum('status', ['DRAFT', 'BELUM', 'SEBAGIAN', 'LUNAS', 'BATAL'])->default('DRAFT');
            $table->foreignId('jurnal_id')->nullable()->constrained('bo_jurnal_header')->nullOnDelete();
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_invoice']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'jatuh_tempo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_ar_invoices');
    }
};
