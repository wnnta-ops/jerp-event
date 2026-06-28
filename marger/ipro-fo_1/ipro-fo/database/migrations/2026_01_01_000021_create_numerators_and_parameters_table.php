<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numerators', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->nullable()->constrained();
            $t->string('code', 32);
            $t->string('prefix', 32);
            $t->string('format', 128);
            $t->unsignedTinyInteger('seq_padding')->default(5);
            $t->enum('reset_strategy', ['never', 'yearly', 'monthly', 'daily'])->default('yearly');
            $t->string('current_period', 16)->nullable();
            $t->unsignedBigInteger('current_seq')->default(0);
            $t->timestamps();
            $t->unique(['tenant_id', 'project_id', 'code']);
        });

        Schema::create('parameters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('group', 64);
            $t->string('key', 128);
            $t->text('value');
            $t->enum('data_type', ['string', 'int', 'decimal', 'bool', 'json'])->default('string');
            $t->string('label')->nullable();
            $t->boolean('is_public')->default(false);
            $t->timestamps();
            $t->unique(['tenant_id', 'group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameters');
        Schema::dropIfExists('numerators');
    }
};
