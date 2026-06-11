<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengalihan Hak — pindah customer kontrak (mis. suami→istri / orang lain).
 * Menyimpan kondisi lama→baru + biaya admin + file scan = history pengalihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_pengalihan_hak', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('no_pengalihan')->nullable();        // PH-...
            $table->foreignId('kontrak_id')->constrained('fo_kontrak')->cascadeOnDelete();
            $table->foreignId('customer_lama_id')->nullable()->constrained('fo_customers')->nullOnDelete();
            $table->foreignId('customer_baru_id')->nullable()->constrained('fo_customers')->nullOnDelete();
            $table->date('tgl_pengalihan')->nullable();
            $table->decimal('biaya_admin', 19, 2)->default(0);
            $table->string('file_scan_path')->nullable();
            $table->string('sales_snapshot')->nullable();       // nama sales saat pengalihan
            $table->string('status')->default('SELESAI');       // DRAFT / SELESAI / BATAL
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_pengalihan']);
            $table->index(['tenant_id', 'kontrak_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_pengalihan_hak');
    }
};
