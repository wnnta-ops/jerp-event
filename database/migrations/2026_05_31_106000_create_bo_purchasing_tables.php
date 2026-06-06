<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_pr', 32);
            $table->date('tgl');
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('dept_id')->nullable()->constrained('bo_hr_dept')->nullOnDelete();
            $table->string('keperluan')->nullable();
            $table->decimal('estimasi_total', 18, 2)->default(0);
            // DRAFT → APPROVED_1 → APPROVED (2nd) → PO; or REJECTED
            $table->enum('status', ['DRAFT', 'APPROVED_1', 'APPROVED', 'REJECTED', 'PO'])->default('DRAFT');
            $table->unsignedTinyInteger('approval_level')->default(0);
            $table->foreignId('po_id')->nullable();
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'no_pr']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('bo_pr_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pr_id')->constrained('bo_purchase_requests')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedSmallInteger('urutan');
            $table->string('deskripsi');
            $table->decimal('qty', 18, 2)->default(1);
            $table->string('satuan', 16)->default('PCS');
            $table->decimal('harga_estimasi', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['pr_id', 'urutan']);
        });

        Schema::create('bo_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_po', 32);
            $table->date('tgl');
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('pr_id')->nullable()->constrained('bo_purchase_requests')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('bo_suppliers')->nullOnDelete();
            $table->decimal('total', 18, 2)->default(0);
            $table->enum('status', ['DRAFT', 'TERKIRIM', 'SELESAI', 'BATAL'])->default('DRAFT');
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'no_po']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('bo_po_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->constrained('bo_purchase_orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedSmallInteger('urutan');
            $table->string('deskripsi');
            $table->decimal('qty', 18, 2)->default(1);
            $table->string('satuan', 16)->default('PCS');
            $table->decimal('harga', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['po_id', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_po_lines');
        Schema::dropIfExists('bo_purchase_orders');
        Schema::dropIfExists('bo_pr_lines');
        Schema::dropIfExists('bo_purchase_requests');
    }
};
