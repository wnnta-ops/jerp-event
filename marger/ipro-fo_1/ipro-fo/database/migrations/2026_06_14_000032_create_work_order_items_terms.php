<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CM-2: Detil Pekerjaan (BoQ/RAB) + Detil Term (jadwal termin) untuk SPK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_work_order_items')) {
            Schema::create('bs_work_order_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('work_order_id')->constrained('bs_work_orders')->cascadeOnDelete();
                $table->integer('seq')->default(1);
                $table->string('description');
                $table->string('unit')->nullable();          // satuan (m2, unit, ls)
                $table->decimal('qty', 18, 2)->default(1);
                $table->decimal('unit_price', 20, 2)->default(0);
                $table->decimal('amount', 20, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bs_work_order_terms')) {
            Schema::create('bs_work_order_terms', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('work_order_id')->constrained('bs_work_orders')->cascadeOnDelete();
                $table->integer('seq')->default(1);
                $table->string('description')->nullable();    // mis. "Termin 1 - DP 30%"
                $table->decimal('percentage', 8, 2)->default(0);
                $table->decimal('amount', 20, 2)->default(0);
                $table->date('due_date')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_work_order_terms');
        Schema::dropIfExists('bs_work_order_items');
    }
};
