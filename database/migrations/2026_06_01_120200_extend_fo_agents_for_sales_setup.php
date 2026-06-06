<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perluas tabel fo_agents (kini berfungsi sebagai "Pendaftaran Sales"):
 *   - project_id (per-project agent assignment)
 *   - sales_type_id, sales_level_id (FK ke master)
 *   - inisial (mis. "TS" untuk Tutik Sugiarti — dipakai di print/voucher)
 *   - alamat, whatsapp, no_sales (manual override, default code)
 *   - is_arebi (flag untuk filter AREBI vs NON AREBI cepat di laporan)
 *   - atasan_id — pointer ke agent yang menjadi atasan langsung
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_agents', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('tenant_id')
                ->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('sales_type_id')->nullable()->after('jenis')
                ->constrained('fo_sales_types')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('sales_level_id')->nullable()->after('sales_type_id')
                ->constrained('fo_sales_levels')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('atasan_id')->nullable()->after('sales_level_id');
            $table->string('inisial', 16)->nullable()->after('code');
            $table->text('alamat')->nullable()->after('email');
            $table->string('whatsapp', 32)->nullable()->after('phone');
            $table->boolean('is_arebi')->default(false)->after('jenis');

            $table->foreign('atasan_id')
                ->references('id')->on('fo_agents')
                ->cascadeOnUpdate()->nullOnDelete();

            $table->index(['tenant_id', 'project_id', 'sales_type_id', 'sales_level_id'], 'fo_agents_setup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fo_agents', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['sales_type_id']);
            $table->dropForeign(['sales_level_id']);
            $table->dropForeign(['atasan_id']);
            $table->dropIndex('fo_agents_setup_idx');
            $table->dropColumn([
                'project_id', 'sales_type_id', 'sales_level_id', 'atasan_id',
                'inisial', 'alamat', 'whatsapp', 'is_arebi',
            ]);
        });
    }
};
