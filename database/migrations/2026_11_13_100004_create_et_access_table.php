<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akses per event per user. scan_point_id terisi = boleh scan di titik itu;
 * baris tanpa scan point dipakai untuk hak live board / laporan saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('et_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('event_id')->constrained('et_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('scan_point_id')->nullable()->constrained('et_scan_points')->cascadeOnDelete();
            $table->boolean('can_live_board')->default(false);
            $table->boolean('can_reports')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['event_id', 'user_id', 'scan_point_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('et_access');
    }
};
