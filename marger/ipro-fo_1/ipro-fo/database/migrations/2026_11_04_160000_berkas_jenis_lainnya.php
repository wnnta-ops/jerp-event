<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Halaman unggah supplier: jenis dokumen tetap Invoice, Kuitansi, Faktur Pajak, dan Lainnya.
 *  - Jenis berkas LAINNYA (tidak wajib) untuk tiap tenant yang sudah punya master kelengkapan berkas.
 *  - bs_ap_berkas_upload_files.keterangan: isi dokumen "Lainnya" yang diketik pengunggah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_berkas_upload_files', function (Blueprint $t): void {
            $t->string('keterangan', 100)->nullable()->after('berkas_id');
        });
        foreach (DB::table('bs_ap_berkas')->distinct()->pluck('tenant_id') as $tenant) {
            if (! DB::table('bs_ap_berkas')->where('tenant_id', $tenant)->where('code', 'LAINNYA')->exists()) {
                DB::table('bs_ap_berkas')->insert([
                    'tenant_id' => $tenant, 'code' => 'LAINNYA', 'name' => 'Lainnya',
                    'is_mandatory' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('bs_ap_berkas')->where('code', 'LAINNYA')->delete();
        Schema::table('bs_ap_berkas_upload_files', function (Blueprint $t): void {
            $t->dropColumn('keterangan');
        });
    }
};
