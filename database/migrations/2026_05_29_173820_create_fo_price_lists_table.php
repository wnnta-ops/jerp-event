<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('unit_type_id')->nullable()->constrained('fo_unit_types')->cascadeOnUpdate()->nullOnDelete();
            $table->string('code', 32);
            $table->date('berlaku_mulai');
            $table->date('berlaku_sampai')->nullable();
            $table->decimal('harga_dasar', 18, 2)->default(0);
            $table->decimal('harga_jual', 18, 2)->default(0);
            $table->decimal('ppn_persen', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'project_id', 'unit_type_id']);
            $table->index(['tenant_id', 'berlaku_mulai']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_price_lists');
    }
};
