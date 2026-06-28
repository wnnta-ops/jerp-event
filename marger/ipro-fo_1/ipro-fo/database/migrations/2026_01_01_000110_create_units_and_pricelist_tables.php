<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_types', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->unsignedInteger('lt')->nullable();
            $t->unsignedInteger('lb')->nullable();
            $t->unsignedTinyInteger('bedrooms')->nullable();
            $t->unsignedTinyInteger('bathrooms')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['project_id', 'code']);
        });

        Schema::create('units', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->constrained();
            $t->foreignId('cluster_id')->nullable()->constrained();
            $t->foreignId('block_id')->nullable()->constrained();
            $t->foreignId('unit_type_id')->nullable()->constrained();
            $t->string('unit_code', 32);
            $t->string('address')->nullable();
            $t->decimal('base_price', 20, 2);
            $t->decimal('current_price', 20, 2);
            $t->string('status', 32)->default('available');
            $t->json('attributes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['project_id', 'unit_code']);
            $t->index(['status', 'project_id']);
        });

        Schema::create('siteplan_points', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->foreignId('unit_id')->constrained();
            $t->decimal('x', 10, 4);
            $t->decimal('y', 10, 4);
            $t->decimal('width', 10, 4)->nullable();
            $t->decimal('height', 10, 4)->nullable();
            $t->decimal('rotation', 6, 2)->default(0);
            $t->string('svg_path')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'unit_id']);
        });

        Schema::create('pricelists', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->constrained();
            $t->string('name');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pricelist_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('pricelist_id')->constrained()->cascadeOnDelete();
            $t->foreignId('unit_id')->constrained();
            $t->decimal('price', 20, 2);
            $t->decimal('discount_max_pct', 5, 2)->default(0);
            $t->timestamps();
            $t->unique(['pricelist_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricelist_items');
        Schema::dropIfExists('pricelists');
        Schema::dropIfExists('siteplan_points');
        Schema::dropIfExists('units');
        Schema::dropIfExists('unit_types');
    }
};
