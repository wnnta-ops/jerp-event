<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('tenant_id')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->string('event', 64);
            $t->string('auditable_type')->nullable();
            $t->unsignedBigInteger('auditable_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->json('context')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['auditable_type', 'auditable_id']);
            $t->index('user_id');
            $t->index('event');
            $t->index('created_at');
        });

        // Partition (MariaDB 11) — comment out if not supported in your env.
        // DB::statement("ALTER TABLE audit_logs PARTITION BY RANGE COLUMNS(created_at) (
        //     PARTITION p202601 VALUES LESS THAN ('2026-02-01'),
        //     PARTITION p202602 VALUES LESS THAN ('2026-03-01'),
        //     PARTITION p202603 VALUES LESS THAN ('2026-04-01'),
        //     PARTITION pmax    VALUES LESS THAN MAXVALUE
        // )");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
