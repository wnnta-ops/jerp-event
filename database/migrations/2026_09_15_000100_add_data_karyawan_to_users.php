<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data karyawan pada akun pengguna — dipakai membuat baris karyawan di BO saat akun dibuat.
 *
 * Setiap akun login adalah karyawan (keputusan 15 Sep 2026), dan dokumen pembayaran BO —
 * SIP & Bukti Permohonan Pembayaran — mencetak unit kerja si pengaju dengan menelusuri
 * users.employee_nip → bs_employees. Selama ini rantai itu putus: employee_nip tidak pernah
 * punya layar untuk diisi, sehingga semua dokumen tercetak "Tanpa Departemen".
 *
 * Nilai di sini adalah DATA AWAL, bukan salinan yang terus disamakan. Setelah baris
 * karyawannya terbentuk di BO, HR yang memegang datanya dan Accounting memegang cost
 * center-nya — modulnya memang berbeda. syncUser hanya membuat, tidak pernah menimpa.
 *
 * bo_cost_center_id sengaja TANPA foreign key: bs_cost_centers ada di database ipro_bo,
 * dan MySQL tidak bisa menautkan antar-database. Pola yang sama sudah dipakai grup akses BO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'nik')) {
                // Unik, tapi nullable: 60 akun lama belum punya NIK, dan MySQL mengizinkan
                // NULL berulang pada indeks unik. Yang tidak boleh adalah dua akun memakai
                // NIK yang sama — itu berarti keduanya menunjuk karyawan yang sama.
                $t->string('nik', 32)->nullable()->after('full_name');
                $t->unique('nik');
            }
            if (! Schema::hasColumn('users', 'npwp')) {
                $t->string('npwp', 32)->nullable()->after('nik');
            }
            if (! Schema::hasColumn('users', 'bank_id')) {
                $t->foreignId('bank_id')->nullable()->after('npwp')
                    ->constrained('banks')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'rekening_number')) {
                $t->string('rekening_number', 64)->nullable()->after('bank_id');
            }
            if (! Schema::hasColumn('users', 'bo_cost_center_id')) {
                $t->unsignedBigInteger('bo_cost_center_id')->nullable()->after('rekening_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'bank_id')) {
                $t->dropConstrainedForeignId('bank_id');
            }
            foreach (['bo_cost_center_id', 'rekening_number', 'npwp'] as $k) {
                if (Schema::hasColumn('users', $k)) {
                    $t->dropColumn($k);
                }
            }
            if (Schema::hasColumn('users', 'nik')) {
                $t->dropUnique(['nik']);
                $t->dropColumn('nik');
            }
        });
    }
};
