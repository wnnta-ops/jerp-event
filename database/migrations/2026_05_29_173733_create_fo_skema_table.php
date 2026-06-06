<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_skema', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->enum('cara_bayar', ['CASH', 'CASH_BERTAHAP', 'CICILAN', 'KPR'])->default('KPR');
            $table->decimal('dp_persen', 5, 2)->default(0);
            $table->unsignedSmallInteger('tenor_bulan')->nullable();
            $table->unsignedSmallInteger('jumlah_termin')->default(1);
            $table->text('keterangan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_skema');
    }
};
