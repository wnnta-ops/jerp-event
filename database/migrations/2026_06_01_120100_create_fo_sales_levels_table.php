<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master "Level Sales" — Sales NON AREBI, Koor NON AREBI, Kantor NON AREBI,
 * Lead Agent NON AREBI, Sales AREBI, Koor AREBI, dst. Hierarki via parent_id
 * (atasan) supaya bisa cetak struktur organisasi sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_sales_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('sales_type_id')->nullable()->constrained('fo_sales_types')->cascadeOnUpdate()->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->foreignId('parent_id')->nullable();  // self-FK, dipasang manual below to avoid circular
            $table->boolean('is_active')->default(true);
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'project_id', 'code'], 'fo_sales_levels_scope_unique');
            $table->index(['tenant_id', 'is_active']);
            $table->index('parent_id');

            $table->foreign('parent_id')
                ->references('id')->on('fo_sales_levels')
                ->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_sales_levels');
    }
};
