<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — Enhance gimmicks (master items) untuk Java Core System Lama parity.
 *
 * Existing: id, code, name, description, value, effective_from/to, is_active
 * Add: gimmick_type, satuan_barang (unit), harga_satuan
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gimmicks', function (Blueprint $table): void {
            $table->string('gimmick_type', 30)->default('voucher');
            $table->string('satuan_barang', 30)->default('VOUCHER'); // 'VOUCHER', 'BUAH', 'M2', 'TAHUN'
            $table->decimal('harga_satuan', 20, 2)->default(0);
            $table->index('gimmick_type');
        });
    }

    public function down(): void
    {
        Schema::table('gimmicks', function (Blueprint $table): void {
            $table->dropIndex(['gimmick_type']);
            $table->dropColumn(['gimmick_type', 'satuan_barang', 'harga_satuan']);
        });
    }
};
