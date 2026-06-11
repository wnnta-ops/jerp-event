<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pindah Unit — ganti unit kontrak (mis. salah unit / pindah blok). Menyimpan
 * unit lama→baru + biaya admin + customer saat pindah = history pindah unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_pindah_unit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('no_pindah')->nullable();            // PU-...
            $table->foreignId('kontrak_id')->constrained('fo_kontrak')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('fo_customers')->nullOnDelete();
            $table->foreignId('unit_lama_id')->nullable()->constrained('fo_units')->nullOnDelete();
            $table->foreignId('unit_baru_id')->nullable()->constrained('fo_units')->nullOnDelete();
            $table->date('tgl_pindah')->nullable();
            $table->decimal('biaya_admin', 19, 2)->default(0);
            $table->string('status')->default('SELESAI');
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_pindah']);
            $table->index(['tenant_id', 'kontrak_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_pindah_unit');
    }
};
