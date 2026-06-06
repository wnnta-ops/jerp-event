<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_ivt_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('kode', 32);
            $table->string('nama');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'kode']);
        });

        Schema::create('bo_ivt_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('kode', 32);
            $table->string('nama');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'kode']);
        });

        Schema::create('bo_ivt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('kode', 32);
            $table->string('nama');
            $table->foreignId('kategori_id')->nullable()->constrained('bo_ivt_categories')->nullOnDelete();
            $table->string('satuan', 16)->default('PCS');
            $table->decimal('stok', 18, 2)->default(0);
            $table->decimal('min_stok', 18, 2)->default(0);
            $table->decimal('harga_pokok', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'kode']);
            $table->index(['tenant_id', 'kategori_id']);
        });

        Schema::create('bo_ivt_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_movement', 32);
            $table->enum('jenis', ['BBB', 'BKB', 'BPB', 'FPB', 'ADJ']); // masuk/keluar/pindah/permintaan/adjustment
            $table->date('tgl');
            $table->foreignId('item_id')->constrained('bo_ivt_items')->restrictOnDelete();
            $table->foreignId('lokasi_id')->nullable()->constrained('bo_ivt_locations')->nullOnDelete();
            $table->enum('arah', ['MASUK', 'KELUAR']);
            $table->decimal('qty', 18, 2);
            $table->decimal('nilai', 18, 2)->default(0);
            $table->string('ref_dokumen', 64)->nullable();
            $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED', 'BATAL'])->default('DRAFT');
            $table->boolean('applied')->default(false); // whether stock has been updated
            $table->text('keterangan')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'no_movement']);
            $table->index(['tenant_id', 'item_id']);
            $table->index(['tenant_id', 'jenis', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_ivt_movements');
        Schema::dropIfExists('bo_ivt_items');
        Schema::dropIfExists('bo_ivt_locations');
        Schema::dropIfExists('bo_ivt_categories');
    }
};
