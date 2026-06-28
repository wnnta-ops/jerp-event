<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('username', 64)->nullable()->unique();
            $t->string('phone', 30)->nullable();
            $t->string('password');
            $t->string('avatar_path')->nullable();
            $t->string('employee_nip', 32)->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_blocked')->default(false);
            $t->unsignedTinyInteger('failed_login_count')->default(0);
            $t->timestamp('blocked_until')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamp('password_changed_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->string('dark_mode', 8)->default('system');
            $t->string('locale', 8)->default('id');
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
            $t->index('is_active');
            $t->index('is_blocked');
        });

        Schema::create('password_reset_tokens', function (Blueprint $t): void {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
