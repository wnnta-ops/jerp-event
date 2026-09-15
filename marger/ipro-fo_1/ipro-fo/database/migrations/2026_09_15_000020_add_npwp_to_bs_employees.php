<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NPWP karyawan.
 *
 * bs_employees sudah menyimpan rekening (bank_name, bank_account) tapi tidak NPWP, padahal
 * keduanya dipakai bersama saat membayar orang. Ditambahkan agar baris karyawan yang dibuat
 * otomatis dari pembuatan akun J-ERP bisa membawa data yang sama lengkapnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_employees', 'npwp')) {
            return;
        }

        Schema::table('bs_employees', function (Blueprint $t) {
            $t->string('npwp', 32)->nullable()->after('bank_account');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_employees', 'npwp')) {
            return;
        }

        Schema::table('bs_employees', function (Blueprint $t) {
            $t->dropColumn('npwp');
        });
    }
};
