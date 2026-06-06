<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('unit_type_id')->nullable()->constrained('fo_unit_types')->nullOnDelete();
            $table->string('blok', 16)->nullable();
            $table->string('no_unit', 32);
            $table->string('cluster')->nullable();
            $table->decimal('luas_tanah', 10, 2)->nullable();
            $table->decimal('luas_bangunan', 10, 2)->nullable();
            $table->decimal('harga_jual', 18, 2)->default(0);
            $table->enum('status', ['TERSEDIA', 'BOOKING', 'TERJUAL', 'LUNAS', 'BATAL', 'HOLD'])
                ->default('TERSEDIA');
            $table->text('keterangan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'project_id', 'blok', 'no_unit'], 'fo_units_blok_no_unique');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_units');
    }
};
