<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunjungan satu NUP di satu pos: started_at (scan Masuk) s.d. ended_at (scan
 * Keluar). Lama layanan = ended − started; waktu tunggu dihitung dari kunjungan
 * pos sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('et_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('participant_id')->constrained('et_participants')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('et_stations')->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('started_by')->nullable();
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->enum('end_reason', ['scan_keluar', 'manual', 'next_scan', 'koreksi', 'event_closed'])->nullable();
            $table->json('flags')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['participant_id', 'station_id']);
            $table->index(['station_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('et_visits');
    }
};
