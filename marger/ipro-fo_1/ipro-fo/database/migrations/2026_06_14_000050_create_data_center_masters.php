<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA CENTER: master pendukung —
 *   bs_supplier_categories (Kategori Supplier sebagai master terkelola)
 *   bs_hr_departments      (HR Department untuk Employee Center)
 * + kolom link nullable di bs_suppliers/bs_employees (kompat: kolom lama tetap).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_supplier_categories')) {
            Schema::create('bs_supplier_categories', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('code')->index();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->string('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bs_hr_departments')) {
            Schema::create('bs_hr_departments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('code')->index();
                $table->string('name');
                $table->string('head')->nullable();      // kepala departemen
                $table->boolean('is_active')->default(true);
                $table->string('notes')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('bs_suppliers') && ! Schema::hasColumn('bs_suppliers', 'category_id')) {
            Schema::table('bs_suppliers', fn (Blueprint $t) => $t->unsignedBigInteger('category_id')->nullable());
        }
        if (Schema::hasTable('bs_employees') && ! Schema::hasColumn('bs_employees', 'department_id')) {
            Schema::table('bs_employees', fn (Blueprint $t) => $t->unsignedBigInteger('department_id')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_supplier_categories');
        Schema::dropIfExists('bs_hr_departments');
    }
};
