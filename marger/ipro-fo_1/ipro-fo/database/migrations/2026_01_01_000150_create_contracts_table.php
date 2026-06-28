<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('booking_id')->constrained();
            $t->string('contract_number', 64)->unique();
            $t->date('signed_at')->nullable();
            $t->string('status', 32)->default('draft');
            $t->unsignedBigInteger('template_id')->nullable();
            $t->json('snapshot')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('contract_clauses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('clause_no');
            $t->string('title');
            $t->text('body');
            $t->timestamps();
            $t->unique(['contract_id', 'clause_no']);
        });

        Schema::create('contract_gimmicks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('gimmick_id')->constrained();
            $t->decimal('value', 20, 2);
            $t->timestamps();
        });

        Schema::create('contract_signers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->string('role', 32);
            $t->string('name');
            $t->string('id_number', 255)->nullable();
            $t->datetime('signed_at')->nullable();
            $t->string('signature_path')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_signers');
        Schema::dropIfExists('contract_gimmicks');
        Schema::dropIfExists('contract_clauses');
        Schema::dropIfExists('contracts');
    }
};
