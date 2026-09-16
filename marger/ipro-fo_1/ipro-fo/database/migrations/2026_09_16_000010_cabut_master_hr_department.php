<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cabut master HR Department — unit kerja karyawan cukup satu, yaitu Cost Center.
 *
 * Ada DUA master yang menanyakan hal sama: bs_hr_departments (HR) dan bs_cost_centers
 * (Accounting). Yang HR tidak pernah diisi — 0 baris — sehingga bs_employees.department_id
 * dan bs_cash_advances.department_id NULL seluruhnya, dan SIP maupun Bukti Permohonan
 * Pembayaran selalu tercetak "Tanpa Departemen". Sementara Cost Center terisi, isinya memang
 * nama divisi, dan sudah dipakai Budget Control serta jurnal.
 *
 * Nama "Departemen" TETAP dipakai di layar HR dan di cetakan; yang disatukan sumber datanya.
 *
 * TIDAK menyentuh bs_journal_lines.department_id — kolom itu menunjuk `bs_departments`,
 * master akuntansi yang BERBEDA dan berisi 3 baris. Nama kolomnya mirip, isinya bukan.
 *
 * Aman dijalankan: sebelum menghapus, dipastikan kolomnya memang kosong. Kalau ternyata ada
 * yang mengisi di sela-sela, migrasi berhenti dengan pesan — lebih baik gagal daripada
 * membuang data diam-diam.
 */
return new class extends Migration
{
    /** @var array<int,array{0:string,1:string}> [tabel, kolom] */
    private const KOLOM = [
        ['bs_employees', 'department_id'],
        ['bs_cash_advances', 'department_id'],
    ];

    public function up(): void
    {
        foreach (self::KOLOM as [$tabel, $kolom]) {
            if (! Schema::hasColumn($tabel, $kolom)) {
                continue;
            }

            $terisi = Illuminate\Support\Facades\DB::table($tabel)->whereNotNull($kolom)->count();
            if ($terisi > 0) {
                throw new RuntimeException(
                    "{$tabel}.{$kolom} ternyata terisi {$terisi} baris — dihentikan. ".
                    'Pindahkan dulu isinya ke cost_center_id sebelum kolom ini dibuang.'
                );
            }

            Schema::table($tabel, function (Blueprint $t) use ($kolom) {
                $t->dropColumn($kolom);
            });
        }

        Schema::dropIfExists('bs_hr_departments');
    }

    public function down(): void
    {
        if (! Schema::hasTable('bs_hr_departments')) {
            Schema::create('bs_hr_departments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->string('code', 40);
                $t->string('name', 150);
                $t->string('head', 100)->nullable();
                $t->boolean('is_active')->default(true);
                $t->string('notes', 200)->nullable();
                $t->timestamps();
            });
        }

        foreach (self::KOLOM as [$tabel, $kolom]) {
            if (Schema::hasColumn($tabel, $kolom)) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $t) use ($kolom) {
                $t->unsignedBigInteger($kolom)->nullable();
            });
        }
    }
};
