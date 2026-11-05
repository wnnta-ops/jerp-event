<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi push app approver (PWA).
 *   fo_push_subscriptions : satu baris per perangkat (langganan Web Push) + pilihan pemicu,
 *                           terikat ke token login perangkat itu (token_id).
 *   fo_push_jejak         : per user — dokumen yang terakhir terlihat (menunggu saya & status
 *                           ajuan saya), supaya job tiap menit hanya memberi tahu yang BARU.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Token login perangkat ini — perangkat dikeluarkan (logout / Perangkat & sesi) → langganan ikut dihapus.
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('perangkat', 120)->nullable();
            $table->boolean('giliran')->default(true);
            $table->boolean('ajuan')->default(true);
            $table->boolean('harian')->default(true);
            $table->string('jam_harian', 5)->default('08:00');
            $table->date('harian_terakhir')->nullable();
            $table->timestamp('terkirim_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fo_push_jejak', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->json('perlu_ids');
            $table->json('ajuan_status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_push_jejak');
        Schema::dropIfExists('fo_push_subscriptions');
    }
};
