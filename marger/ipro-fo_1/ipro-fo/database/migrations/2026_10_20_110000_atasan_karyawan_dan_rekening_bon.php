<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekening tujuan transfer Bon Sementara dipilih saat pengajuan: rekening pengaju sendiri
 * atau rekening ATASAN-nya.
 *
 *   bs_employees.atasan_id  : atasan langsung (karyawan lain) — diisi di Data Karyawan.
 *   bs_cash_advances.rek_*  : SALINAN rekening yang dipilih saat bon diajukan. Disalin, bukan
 *                             dirujuk: bila data karyawan dibetulkan belakangan (ejaan nama,
 *                             no. rekening), bon yang sudah berjalan tidak ikut berubah.
 *
 * Bon lama (rek_* kosong) tetap mencetak rekening hasil pencocokan nama pemegang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_employees', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_employees', 'atasan_id')) {
                $t->unsignedBigInteger('atasan_id')->nullable()->index();
            }
        });

        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_cash_advances', 'rek_sumber')) {
                $t->string('rek_sumber', 10)->nullable();          // sendiri | atasan
                $t->unsignedBigInteger('rek_employee_id')->nullable();
                $t->string('rek_bank', 100)->nullable();
                $t->string('rek_nomor', 60)->nullable();
                $t->string('rek_atas_nama', 150)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            if (Schema::hasColumn('bs_cash_advances', 'rek_sumber')) {
                $t->dropColumn(['rek_sumber', 'rek_employee_id', 'rek_bank', 'rek_nomor', 'rek_atas_nama']);
            }
        });
        Schema::table('bs_employees', function (Blueprint $t): void {
            if (Schema::hasColumn('bs_employees', 'atasan_id')) {
                $t->dropIndex(['atasan_id']);
                $t->dropColumn('atasan_id');
            }
        });
    }
};
