<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Checklist Upload Berkas: tambah jenis berkas MOU dan Bukti Serah Terima (Faktur Pajak sudah ada).
 * Keduanya tidak wajib — tampil di checklist, tapi tidak menahan pengajuan.
 *
 * Hanya untuk tenant yang sudah punya master kelengkapan berkas; DB baru mendapatkannya dari
 * ApCategorySeeder (migrasi berjalan sebelum seeder).
 */
return new class extends Migration
{
    private const JENIS = [
        ['MOU', 'MOU / Perjanjian'],
        ['SERAH-TERIMA', 'Bukti Serah Terima'],
    ];

    public function up(): void
    {
        foreach (DB::table('bs_ap_berkas')->distinct()->pluck('tenant_id') as $tenant) {
            foreach (self::JENIS as [$code, $name]) {
                $ada = DB::table('bs_ap_berkas')->where('tenant_id', $tenant)->where('code', $code)->exists();
                if (! $ada) {
                    DB::table('bs_ap_berkas')->insert([
                        'tenant_id' => $tenant, 'code' => $code, 'name' => $name,
                        'is_mandatory' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('bs_ap_berkas')->whereIn('code', array_column(self::JENIS, 0))->delete();
    }
};
