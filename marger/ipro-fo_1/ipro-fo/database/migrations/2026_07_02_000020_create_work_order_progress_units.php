<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian Kontrol Progress Lapangan per UNIT RUMAH (registrasi massal).
 * Satu KPL (bs_work_order_progress) = beberapa baris unit; nilai (DPP) tiap unit =
 * porsi kontrak SPK per unit (berdasar harga) × progress%. Menyediakan data biaya
 * per unit untuk sinkronisasi ke HPP/CIP per unit (modul COGS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_work_order_progress_units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('progress_id')->constrained('bs_work_order_progress')->cascadeOnDelete();
            $table->unsignedBigInteger('unit_id')->nullable()->index();
            $table->string('unit_code', 50)->nullable();
            $table->decimal('progress_pct', 5, 2)->default(0);      // progress% termin ini utk unit
            $table->decimal('contract_portion', 18, 2)->default(0); // porsi nilai kontrak SPK utk unit
            $table->decimal('dpp_value', 18, 2)->default(0);        // = contract_portion × progress%
            $table->timestamps();

            $table->index(['unit_id', 'progress_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_work_order_progress_units');
    }
};
