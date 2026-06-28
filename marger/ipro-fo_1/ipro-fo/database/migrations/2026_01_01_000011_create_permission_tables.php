<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('module', 64);
            $t->string('label');
            $t->string('guard_name')->default('web');
            $t->timestamps();
            $t->unique(['name', 'guard_name']);
            $t->index('module');
        });

        Schema::create('roles', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('tenant_id')->nullable()->constrained();
            $t->string('name');
            $t->string('description')->nullable();
            $t->string('guard_name')->default('web');
            $t->boolean('is_system')->default(false);
            $t->timestamps();
            $t->unique(['tenant_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $t->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $t->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $t): void {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $t->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
            $t->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $t->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });

        Schema::create('user_companies', function (Blueprint $t): void {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->primary(['user_id', 'company_id']);
        });

        Schema::create('user_projects', function (Blueprint $t): void {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->primary(['user_id', 'project_id']);
        });

        app('cache')->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        Schema::dropIfExists('user_projects');
        Schema::dropIfExists('user_companies');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
