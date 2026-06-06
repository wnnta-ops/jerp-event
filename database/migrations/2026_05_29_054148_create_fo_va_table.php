<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_va', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('fo_customers')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('bank_id')->constrained('banks')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('va_number', 64);
            $table->string('rekening_holder')->nullable();
            $table->date('tgl_aktif')->nullable();
            $table->date('tgl_kadaluarsa')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'bank_id', 'va_number'], 'fo_va_bank_number_unique');
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_va');
    }
};
