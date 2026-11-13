<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Titik scan per pos: Masuk + Keluar (dua HP terpisah), atau single.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('et_scan_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('station_id')->constrained('et_stations')->cascadeOnDelete();
            $table->enum('kind', ['masuk', 'keluar', 'single']);
            $table->string('label');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('et_scan_points');
    }
};
