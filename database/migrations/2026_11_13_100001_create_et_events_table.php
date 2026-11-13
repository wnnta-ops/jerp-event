<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Event — satu baris per event (mis. Pilih Unit launching).
 * fo_nup_event_id sengaja TANPA foreign key: FO tetap tak tersentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('et_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->date('event_date');
            $table->string('venue')->nullable();
            $table->unsignedBigInteger('fo_nup_event_id')->index();
            $table->enum('status', ['draft', 'open', 'closed'])->default('draft');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('et_events');
    }
};
