<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->after('id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('full_name')->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('email_verified_at');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->unsignedSmallInteger('failed_attempts')->default(0)->after('last_login_ip');
            $table->timestamp('locked_until')->nullable()->after('failed_attempts');

            $table->index('tenant_id');
            // Replace global unique on email with (tenant_id, email) for multi-tenant
            $table->dropUnique('users_email_unique');
            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->unique('email');

            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['full_name', 'is_active', 'last_login_at', 'last_login_ip', 'failed_attempts', 'locked_until']);
        });
    }
};
