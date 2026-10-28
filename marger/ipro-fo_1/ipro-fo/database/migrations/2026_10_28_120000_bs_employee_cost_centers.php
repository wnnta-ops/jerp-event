<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departemen TAMBAHAN karyawan — satu karyawan bisa bekerja untuk lebih dari satu cost center.
 *
 * Departemen UTAMA tetap di bs_employees.cost_center_id: SIP, Bukti Permohonan Pembayaran,
 * Registrasi/Posting Invoice, Memo Internal, dan import karyawan semuanya membaca kolom itu dan
 * tidak perlu diubah. Tabel ini hanya menampung yang lain — dipilih pada Pengajuan Bon Sementara
 * dan ikut membuka bon tim departemen tersebut.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_employee_cost_centers')) {
            return;
        }
        Schema::create('bs_employee_cost_centers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('employee_id')->constrained('bs_employees')->cascadeOnDelete();
            $t->foreignId('cost_center_id')->constrained('bs_cost_centers')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['employee_id', 'cost_center_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_employee_cost_centers');
    }
};
