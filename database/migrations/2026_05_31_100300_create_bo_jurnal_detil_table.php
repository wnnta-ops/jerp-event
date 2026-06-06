<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_jurnal_detil', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jurnal_id')->constrained('bo_jurnal_header')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedSmallInteger('urutan');
            $table->string('kode_account', 32);
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->cascadeOnUpdate()->nullOnDelete();
            $table->decimal('debet', 18, 2)->default(0);
            $table->decimal('kredit', 18, 2)->default(0);
            $table->string('keterangan')->nullable();
            $table->timestamps();

            $table->unique(['jurnal_id', 'urutan']);
            $table->index('kode_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_jurnal_detil');
    }
};
