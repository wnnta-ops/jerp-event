<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost center pada Pengajuan Bon Sementara — dimensi yang menautkan bon ke Budget Control.
 *
 * NULLABLE, meski di layar WAJIB diisi. Kolom ini turun ke basis data yang sudah berisi bon
 * lama; membuatnya NOT NULL berarti harus menebak cost center untuk bon yang memang tidak
 * pernah punya, dan tebakan itu akan terbaca sebagai realisasi anggaran departemen yang
 * sebenarnya tidak menanggungnya. Kewajibannya ditegakkan di jalur pembuatan (validasi request
 * + service), bukan dengan memaksa data lama mengaku punya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_cash_advances', 'cost_center_id')) {
            return;
        }

        Schema::table('bs_cash_advances', function (Blueprint $t) {
            $t->foreignId('cost_center_id')->nullable()->after('department_id')
                ->constrained('bs_cost_centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_cash_advances', 'cost_center_id')) {
            return;
        }

        Schema::table('bs_cash_advances', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cost_center_id');
        });
    }
};
