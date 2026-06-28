<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — Enhance contract_gimmicks dengan tracking diberikan/diterima.
 *
 * Existing: contract_id, gimmick_id, value
 * Add: qty, harga_satuan, total_harga, target_lunas,
 *      diberikan, diterima, tgl_diterima, notes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_gimmicks', function (Blueprint $table): void {
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('harga_satuan', 20, 2)->default(0);
            $table->decimal('total_harga', 20, 2)->default(0);
            $table->date('target_lunas')->nullable();

            // Status tracking
            $table->boolean('diberikan')->default(true); // janji di kontrak
            $table->boolean('diterima')->default(false);    // sudah delivery ke customer
            $table->date('tgl_diterima')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('gimmick_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('contract_gimmicks', function (Blueprint $table): void {
            $table->dropForeign(['gimmick_package_id']);
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'qty', 'harga_satuan', 'total_harga', 'target_lunas',
                'diberikan', 'diterima', 'tgl_diterima', 'notes',
                'gimmick_package_id', 'created_by', 'deleted_at',
            ]);
        });
    }
};
