<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CM-1: Master Project (BO: Contractor Management → Setup → Project).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_projects')) {
            return;
        }
        Schema::create('bs_projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code')->index();
            $table->string('name');
            $table->string('dept')->nullable();        // departemen/PT pengelola
            $table->boolean('is_active')->default(true);
            $table->string('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_projects');
    }
};
