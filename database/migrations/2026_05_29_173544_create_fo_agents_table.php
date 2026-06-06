<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->enum('jenis', ['INTERNAL', 'EXTERNAL', 'KORPORASI'])->default('EXTERNAL');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('npwp', 32)->nullable();
            $table->string('rekening_bank', 32)->nullable();
            $table->string('rekening_number', 64)->nullable();
            $table->string('rekening_holder')->nullable();
            $table->decimal('komisi_persen_default', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_agents');
    }
};
