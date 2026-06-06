<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_hr_dept', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('kode', 32);
            $table->string('nama');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'kode']);
        });

        Schema::create('bo_hr_karyawan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('nik', 32);
            $table->string('nama');
            $table->foreignId('dept_id')->nullable()->constrained('bo_hr_dept')->nullOnDelete();
            $table->string('jabatan')->nullable();
            $table->date('tgl_masuk')->nullable();
            $table->enum('status', ['AKTIF', 'NONAKTIF'])->default('AKTIF');
            $table->string('email')->nullable();
            $table->string('telp', 32)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'nik']);
            $table->index(['tenant_id', 'dept_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_hr_karyawan');
        Schema::dropIfExists('bo_hr_dept');
    }
};
