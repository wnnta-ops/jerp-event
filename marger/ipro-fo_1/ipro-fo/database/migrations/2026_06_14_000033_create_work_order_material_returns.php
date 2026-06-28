<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CM-5: Pengembalian Sisa Pemakaian (retur material SBO) — Dr Persediaan / Cr CIP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_work_order_material_returns')) {
            return;
        }
        Schema::create('bs_work_order_material_returns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('work_order_id')->constrained('bs_work_orders')->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('bs_work_order_materials')->nullOnDelete();
            $table->date('return_date');
            $table->string('description')->nullable();
            $table->decimal('amount', 20, 2)->default(0);
            $table->unsignedBigInteger('inventory_account_id');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_work_order_material_returns');
    }
};
