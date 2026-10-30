<?php

use App\Modules\FO\Komunikasi\Support\DokumenBawaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Template Dokumen: SPR v1 & SPRS v1 (semua cluster, semua tahap) berisi teks bawaan aplikasi,
 * supaya menu langsung menampilkan teks yang sedang dipakai dan bisa dibuat "Versi baru dari ini".
 *
 * Isinya SAMA PERSIS dengan teks bawaan → cetakan tidak berubah. Tanggal berlaku lampau agar
 * langsung "Berlaku" untuk semua kontrak. Dilewati bila dokumen itu sudah punya versi umum.
 */
return new class extends Migration
{
    private const CATATAN = 'Teks bawaan aplikasi (versi awal otomatis)';

    public function up(): void
    {
        $tenantId = DB::table('tenants')->orderBy('id')->value('id');
        $now = now();

        foreach ([DokumenBawaan::SPR, DokumenBawaan::SPRS] as $dokumen) {
            $ada = DB::table('fo_template_dokumen')->where('dokumen', $dokumen)
                ->whereNull('lokasi_id')->whereNull('tahap_id')->exists();
            if ($ada) {
                continue;
            }
            DB::table('fo_template_dokumen')->insert([
                'tenant_id' => $tenantId,
                'dokumen' => $dokumen,
                'lokasi_id' => null,
                'tahap_id' => null,
                'versi' => 1,
                'berlaku_mulai' => '2020-01-01',
                'isi' => json_encode(DokumenBawaan::untuk($dokumen), JSON_UNESCAPED_UNICODE),
                'catatan' => self::CATATAN,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('fo_template_dokumen')->where('catatan', self::CATATAN)->where('versi', 1)->delete();
    }
};
