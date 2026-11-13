<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log setiap percobaan scan — diterima maupun ditolak. Append-only: tanpa
 * updated_at / soft delete. Kolom effect (tambahan di luar brief) dipakai untuk
 * membatalkan scan terakhir tanpa menebak-nebak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('et_scan_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('event_id')->constrained('et_events')->cascadeOnDelete();
            $table->unsignedBigInteger('scan_point_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('raw_payload', 255);
            $table->unsignedBigInteger('fo_nup_registration_id')->nullable()->index();
            $table->enum('result', ['accepted', 'rejected', 'duplicate', 'undone']);
            $table->string('reason')->nullable();
            $table->timestamp('server_time', 3);
            $table->string('user_agent', 255)->nullable();
            // Apa yang diubah scan ini (visit dibuka/ditutup, keadaan peserta sebelumnya)
            // — bahan "Batalkan scan terakhir". Baris undo menyimpan {"undo_of": id}.
            $table->json('effect')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['event_id', 'server_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('et_scan_logs');
    }
};
