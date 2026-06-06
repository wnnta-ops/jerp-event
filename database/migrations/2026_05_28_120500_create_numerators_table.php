<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numerators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 32);            // e.g. 'KPR', 'TTS', 'BKM'
            $table->string('format');              // e.g. 'KPR-{YYYY}-{seq:5}'
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->string('period', 16)->nullable(); // e.g. '2026' for yearly reset
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'company_id', 'code', 'period'], 'numerators_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numerators');
    }
};
