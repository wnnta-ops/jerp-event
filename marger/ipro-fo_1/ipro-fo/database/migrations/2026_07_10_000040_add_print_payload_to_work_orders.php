<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simpan dokumen Cetak SPK per-SPK (bs_work_orders.print_payload).
 * Menyimpan state editor Cetak SPK (judul, pasal, rincian, dll.) agar hasil
 * editan tersimpan per-SPK; bila kosong, Cetak SPK memakai draft Set Up SPK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            $t->json('print_payload')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            $t->dropColumn('print_payload');
        });
    }
};
