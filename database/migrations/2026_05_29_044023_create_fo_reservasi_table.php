<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_reservasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_reservasi', 32);
            $table->foreignId('customer_id')->constrained('fo_customers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('fo_units')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('tgl_reservasi');
            $table->date('tgl_kadaluarsa')->nullable();         // expiry untuk booking
            $table->decimal('harga_jual', 18, 2)->default(0);
            $table->decimal('booking_fee', 18, 2)->default(0);
            $table->enum('status', ['AKTIF', 'KADALUARSA', 'BATAL', 'NAIK_KONTRAK'])
                ->default('AKTIF');
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_reservasi']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'unit_id']);
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_reservasi');
    }
};
