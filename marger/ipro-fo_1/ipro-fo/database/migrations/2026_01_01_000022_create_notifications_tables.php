<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('event', 128);
            $t->string('channel', 32);
            $t->string('subject')->nullable();
            $t->text('body');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'event', 'channel']);
        });

        Schema::create('notifications_outbox', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('tenant_id')->nullable();
            $t->string('event', 128);
            $t->string('channel', 32);
            $t->string('notifiable_type');
            $t->unsignedBigInteger('notifiable_id');
            $t->json('payload');
            $t->json('rendered')->nullable();
            $t->enum('status', ['pending', 'sending', 'sent', 'failed', 'dead'])->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('scheduled_at')->useCurrent();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();

            $t->index(['notifiable_type', 'notifiable_id']);
            $t->index(['status', 'scheduled_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('event', 128);
            $t->json('channels');
            $t->boolean('enabled')->default(true);
            $t->timestamps();
            $t->unique(['user_id', 'event']);
        });

        Schema::create('notifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications_outbox');
        Schema::dropIfExists('notification_templates');
    }
};
