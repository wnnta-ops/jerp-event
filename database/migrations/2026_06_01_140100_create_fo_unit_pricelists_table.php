<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricelist per unit per skema. Diisi dari Excel Upload Price List dengan
 * kolom: No. Unit, No. Skema, Price List Minimum, Price List Kavling Minimum,
 * Price List, Price List Kavling.
 *
 * Unit boleh punya banyak pricelist (per skema bayar berbeda — KPR, Cash,
 * Cash Bertahap, dst.) tapi (unit_id, skema_id) unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_unit_pricelists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('fo_units')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('skema_id')->nullable()->constrained('fo_skema')->cascadeOnUpdate()->nullOnDelete();
            $table->string('no_skema', 32)->nullable();   // raw nomor skema dari Excel kalau skema_id tidak ketemu
            $table->decimal('price_list_minimum', 18, 2)->default(0);
            $table->decimal('price_list_kavling_minimum', 18, 2)->default(0);
            $table->decimal('price_list', 18, 2)->default(0);
            $table->decimal('price_list_kavling', 18, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['unit_id', 'skema_id'], 'fo_unit_pricelists_unit_skema_unique');
            $table->index(['tenant_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_unit_pricelists');
    }
};
