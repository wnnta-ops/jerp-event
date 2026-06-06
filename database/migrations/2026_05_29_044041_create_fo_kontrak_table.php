<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_kontrak', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_kontrak', 32);
            $table->foreignId('reservasi_id')->nullable()->constrained('fo_reservasi')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('fo_customers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('fo_units')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('tgl_kontrak');
            $table->decimal('harga_jual', 18, 2)->default(0);
            $table->decimal('diskon', 18, 2)->default(0);
            $table->decimal('ppn', 18, 2)->default(0);
            $table->decimal('total_kontrak', 18, 2)->default(0);
            $table->enum('cara_bayar', ['CASH', 'CASH_BERTAHAP', 'CICILAN', 'KPR'])->default('KPR');
            $table->unsignedSmallInteger('tenor_bulan')->nullable();        // untuk CICILAN
            $table->decimal('dp_persen', 5, 2)->nullable();
            $table->decimal('dp_amount', 18, 2)->nullable();
            $table->enum('status', ['DRAFT', 'BOOKING', 'AKTIF', 'LUNAS', 'BATAL'])->default('DRAFT');
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_kontrak']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_kontrak');
    }
};
