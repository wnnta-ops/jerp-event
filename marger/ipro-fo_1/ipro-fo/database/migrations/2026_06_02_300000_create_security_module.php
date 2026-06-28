<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul SECURITY (eProperty): matrikulasi akses (perusahaan/project), absensi,
 * job history, technical problem. Security Log memakai tabel audit_logs yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Matrikulasi Perusahaan — akses user × perusahaan.
        Schema::create('sec_user_companies', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('company_id');
            $t->timestamps();
            $t->unique(['user_id', 'company_id']);
        });

        // Matrikulasi Project — akses user × project.
        Schema::create('sec_user_projects', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('project_id');
            $t->timestamps();
            $t->unique(['user_id', 'project_id']);
        });

        // Absensi karyawan.
        Schema::create('sec_attendances', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('employee_id');
            $t->date('date');
            $t->time('clock_in')->nullable();
            $t->time('clock_out')->nullable();
            $t->string('status', 12)->default('hadir'); // hadir/izin/sakit/alpa/cuti/dinas
            $t->string('note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'date']);
        });

        // Job History — riwayat jabatan karyawan.
        Schema::create('sec_job_histories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('employee_id');
            $t->string('position');
            $t->string('division')->nullable();
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->string('note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        // Technical Problem — log masalah teknis.
        Schema::create('sec_technical_problems', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('ticket_no', 30)->unique();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('module')->nullable();
            $t->string('priority', 10)->default('medium'); // low/medium/high
            $t->string('status', 16)->default('open');      // open/in_progress/resolved/closed
            $t->unsignedBigInteger('reported_by')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->date('resolved_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sec_technical_problems');
        Schema::dropIfExists('sec_job_histories');
        Schema::dropIfExists('sec_attendances');
        Schema::dropIfExists('sec_user_projects');
        Schema::dropIfExists('sec_user_companies');
    }
};
