<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_jurnal_header', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_jurnal', 32);
            $table->date('tgl_jurnal');
            $table->string('periode', 7);               // 'YYYY-MM' for closed-period guard
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('jenis_dokumen', 32);        // JU, BKM, BKK, INV-AP, FOBO, ...
            $table->string('no_dokumen', 64)->nullable();
            $table->text('keterangan')->nullable();
            $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED', 'CANCELLED'])->default('DRAFT');
            $table->boolean('posted')->default(false);
            $table->decimal('total_debet', 18, 2)->default(0);
            $table->decimal('total_kredit', 18, 2)->default(0);
            // Polymorphic trace back to the source document (CB voucher, AP invoice, FOBO posting…)
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('bo_jurnal_header')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_jurnal']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'periode']);
            $table->index(['tenant_id', 'tgl_jurnal']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_jurnal_header');
    }
};
