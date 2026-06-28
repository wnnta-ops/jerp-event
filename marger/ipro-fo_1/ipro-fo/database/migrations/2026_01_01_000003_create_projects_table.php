<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('company_id')->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->string('location')->nullable();
            $t->date('launched_at')->nullable();
            $t->json('siteplan_meta')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('clusters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->constrained()->cascadeOnUpdate();
            $t->string('code', 32);
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['project_id', 'code']);
        });

        Schema::create('blocks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('cluster_id')->constrained()->cascadeOnUpdate();
            $t->string('code', 32);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['cluster_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('clusters');
        Schema::dropIfExists('projects');
    }
};
