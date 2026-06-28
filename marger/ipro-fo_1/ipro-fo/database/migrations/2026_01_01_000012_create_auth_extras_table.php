<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_secrets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('secret');
            $t->json('recovery_codes')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
            $t->unique('user_id');
        });

        Schema::create('personal_access_tokens', function (Blueprint $t): void {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->string('device_name')->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->string('last_ip', 45)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('password_histories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('password');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('mfa_secrets');
    }
};
