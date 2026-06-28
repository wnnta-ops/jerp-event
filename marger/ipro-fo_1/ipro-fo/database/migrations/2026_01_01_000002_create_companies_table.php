<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnUpdate();
            $t->string('code', 32);
            $t->string('name');
            $t->string('npwp', 20)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
