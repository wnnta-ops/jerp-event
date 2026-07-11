<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alokasi unit rumah pada SPK (Detil Pekerjaan per unit).
 * unit_price = harga satuan / porsi kontrak per unit, ditetapkan saat registrasi SPK.
 * Dipakai KPL: Porsi Kontrak = unit_price, DPP = unit_price × progress%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_work_order_units', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->foreignId('work_order_id')->constrained('bs_work_orders')->cascadeOnDelete();
            $t->unsignedBigInteger('unit_id')->nullable()->index();
            $t->string('unit_code', 50)->nullable();
            $t->decimal('unit_price', 20, 2)->default(0);   // harga satuan = porsi kontrak
            $t->integer('seq')->default(1);
            $t->timestamps();
            $t->unique(['work_order_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_work_order_units');
    }
};
