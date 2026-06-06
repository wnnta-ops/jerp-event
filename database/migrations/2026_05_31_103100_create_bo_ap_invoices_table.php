<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_ap_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_invoice', 32);
            $table->enum('jenis', ['PO', 'NONPO'])->default('NONPO');
            $table->date('tgl');
            $table->date('jatuh_tempo');
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('bo_suppliers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('po_id')->nullable();           // links to bo_purchase_orders (Fase 6)
            $table->string('akun_beban', 32)->default('5.9.01.001');
            $table->string('akun_hutang', 32)->default('2.1.01.001');
            $table->decimal('nominal', 18, 2)->default(0);     // DPP
            $table->decimal('ppn', 18, 2)->default(0);
            $table->decimal('pph', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);       // nominal + ppn - pph
            $table->decimal('dibayar', 18, 2)->default(0);
            $table->decimal('sisa', 18, 2)->default(0);
            $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED', 'SEBAGIAN', 'LUNAS', 'BATAL'])->default('DRAFT');
            $table->foreignId('jurnal_id')->nullable()->constrained('bo_jurnal_header')->nullOnDelete();
            $table->text('keterangan')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_invoice']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'supplier_id']);
            $table->index(['tenant_id', 'jatuh_tempo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_ap_invoices');
    }
};
