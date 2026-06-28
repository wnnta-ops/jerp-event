<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUSINESS SUPPORT — Employee Center (HRD) + Payroll (penggajian).
 * Proses payroll per periode → jurnal GL otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_employees', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('nik', 32)->nullable();
            $t->string('name');
            $t->string('position')->nullable();
            $t->unsignedBigInteger('cost_center_id')->nullable();
            $t->date('join_date')->nullable();
            $t->decimal('base_salary', 18, 2)->default(0);
            $t->decimal('allowance', 18, 2)->default(0);
            $t->string('bank_name')->nullable();
            $t->string('bank_account')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_payroll_runs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('run_number', 48)->unique();
            $t->unsignedSmallInteger('period_year');
            $t->unsignedTinyInteger('period_month');
            $t->date('run_date');
            $t->string('description')->nullable();
            $t->decimal('total_bruto', 20, 2)->default(0);
            $t->decimal('total_deduction', 20, 2)->default(0);
            $t->decimal('total_net', 20, 2)->default(0);
            $t->string('status', 12)->default('posted'); // posted
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_payroll_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payroll_run_id')->constrained('bs_payroll_runs')->cascadeOnDelete();
            $t->unsignedBigInteger('employee_id');
            $t->string('employee_name');
            $t->decimal('gaji_pokok', 18, 2)->default(0);
            $t->decimal('tunjangan', 18, 2)->default(0);
            $t->decimal('pph21', 18, 2)->default(0);
            $t->decimal('bpjs', 18, 2)->default(0);
            $t->decimal('potongan_lain', 18, 2)->default(0);
            $t->decimal('bruto', 18, 2)->default(0);
            $t->decimal('net', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_payroll_lines');
        Schema::dropIfExists('bs_payroll_runs');
        Schema::dropIfExists('bs_employees');
    }
};
