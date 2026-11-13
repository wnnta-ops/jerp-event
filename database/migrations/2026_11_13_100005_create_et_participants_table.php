<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peserta event = SATU NUP (bukan satu customer). Customer dengan dua NUP
 * punya dua baris, masing-masing antre sendiri. Nama & HP di-snapshot dari FO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('et_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('event_id')->constrained('et_events')->cascadeOnDelete();
            $table->unsignedBigInteger('fo_nup_registration_id')->index();
            $table->string('no_nup', 50)->nullable()->index();
            $table->string('customer_code', 50)->nullable()->index();
            $table->string('name_snapshot')->nullable();
            $table->string('phone_snapshot', 50)->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->enum('status', ['active', 'finished', 'left', 'cancelled'])->default('active');
            $table->foreignId('current_station_id')->nullable()->constrained('et_stations')->nullOnDelete();
            $table->enum('current_state', ['waiting', 'in_service', 'done'])->default('waiting');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['event_id', 'fo_nup_registration_id']);
            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('et_participants');
    }
};
