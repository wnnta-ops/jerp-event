<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Insentif (eProperty KOMISI → BLT & Reward): Setup Skema, Pengajuan, Realisasi.
 * Melengkapi Commission (komisi persentase) dengan jenis insentif BLT & Reward.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Setup / master skema insentif per jenis.
        Schema::create('incentive_schemes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('type', 10);            // blt | reward | komisi
            $t->string('code', 32);
            $t->string('name');
            $t->string('basis', 8)->default('amount'); // amount | percent
            $t->decimal('value', 20, 2)->default(0);
            $t->unsignedBigInteger('unit_type_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'type', 'code']);
        });

        // Pengajuan + realisasi insentif.
        Schema::create('incentives', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('incentive_no', 30)->unique();
            $t->string('type', 10);                       // blt | reward
            $t->string('beneficiary_type', 8);            // sales | agent
            $t->unsignedBigInteger('sales_person_id')->nullable();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('booking_id')->nullable();
            $t->unsignedBigInteger('scheme_id')->nullable();
            $t->string('description')->nullable();
            $t->decimal('amount', 20, 2)->default(0);
            // diajukan | disetujui | ditolak | direalisasi
            $t->string('status', 12)->default('diajukan');
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('realized_by')->nullable();
            $t->timestamp('realized_at')->nullable();
            $t->string('reject_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incentives');
        Schema::dropIfExists('incentive_schemes');
    }
};
