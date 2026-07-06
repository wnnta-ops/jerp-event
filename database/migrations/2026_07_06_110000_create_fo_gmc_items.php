<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rincian komponen GMC/gimmick (mis. PPJB = Jasa Notaris + PNBP + Jasa CV Notaris),
        // tiap komponen punya supplier sendiri (bs_suppliers @ ipro_bo) + nilai manual.
        Schema::create('fo_gmc_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gmc_id')->constrained('fo_gmc')->cascadeOnDelete();
            $table->string('nama');                                  // nama komponen
            $table->unsignedBigInteger('supplier_id')->nullable();   // = bs_suppliers.id (ipro_bo)
            $table->decimal('nilai', 15, 2)->default(0);
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_gmc_items');
    }
};
