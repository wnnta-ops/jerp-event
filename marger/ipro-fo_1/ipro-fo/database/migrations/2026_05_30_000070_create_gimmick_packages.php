<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — Gimmick Packages (per cluster).
 *
 *  - gimmick_packages: paket promo per cluster (mis. "CLUSTER 3.4")
 *  - gimmick_package_items: pivot — items dalam package + qty_default
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gimmick_packages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('project_id')->nullable()->constrained();
            $t->string('cluster_code', 50)->nullable()->index();
            $t->string('code', 30);
            $t->string('name', 150);
            $t->text('description')->nullable();
            $t->date('valid_from')->nullable();
            $t->date('valid_until')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('gimmick_package_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gimmick_package_id')->constrained()->cascadeOnDelete();
            $t->foreignId('gimmick_id')->constrained();
            $t->unsignedInteger('qty_default')->default(1);
            $t->unsignedInteger('target_lunas_days')->nullable(); // target lunas dalam X hari setelah kontrak
            $t->timestamps();

            $t->unique(['gimmick_package_id', 'gimmick_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gimmick_package_items');
        Schema::dropIfExists('gimmick_packages');
    }
};
