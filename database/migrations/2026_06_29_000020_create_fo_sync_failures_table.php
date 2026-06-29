<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox kegagalan sinkronisasi FO → BO (ipro_bo) & posting GL. Kegagalan tak boleh
 * menggagalkan simpan FO, tapi WAJIB terlihat & bisa di-replay (jangan diam-diam).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_sync_failures', function (Blueprint $t): void {
            $t->id();
            $t->string('target', 32)->default('ipro_bo');
            $t->string('entity', 64);            // customer/unit/kontrak/tts/kpr/gl
            $t->string('entity_id', 64)->nullable();
            $t->string('operation', 64);          // method sync / jenis GL
            $t->text('error');
            $t->boolean('resolved')->default(false);
            $t->timestamps();
            $t->index(['entity', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_sync_failures');
    }
};
