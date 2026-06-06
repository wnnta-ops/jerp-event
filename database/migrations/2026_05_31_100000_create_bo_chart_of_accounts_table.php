<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('kode', 32);                 // e.g. 1.1.01.001
            $table->string('nama');
            $table->enum('tipe_akun', ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE']);
            $table->enum('saldo_normal', ['DEBET', 'KREDIT']);
            $table->unsignedTinyInteger('level')->default(1);
            $table->string('parent_kode', 32)->nullable();
            $table->boolean('is_pajak')->default(false);
            $table->boolean('is_header')->default(false); // header accounts cannot be posted to
            $table->boolean('is_active')->default(true);
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'kode']);
            $table->index(['tenant_id', 'parent_kode']);
            $table->index(['tenant_id', 'tipe_akun']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_chart_of_accounts');
    }
};
