<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pos (checkpoint) per event, berurutan. Pos pertama selalu fo_checkin
 * (Aktivasi NUP di FO), pos terakhir finish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('et_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('event_id')->constrained('et_events')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->unsignedSmallInteger('sequence');
            $table->enum('type', ['fo_checkin', 'normal', 'finish'])->default('normal');
            $table->unsignedSmallInteger('target_minutes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('et_stations');
    }
};
