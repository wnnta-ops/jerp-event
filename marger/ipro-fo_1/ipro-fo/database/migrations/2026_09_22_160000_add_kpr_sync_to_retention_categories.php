<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Sinkron dengan Master Data KPR" pada master Kategori Retensi.
 *
 * Layar Master Data KPR membentuk kolom Collection Retensi dari master ini — satu kategori
 * satu kolom. Akibatnya TANAH MATANG 50%, 20%, dan 30% berdiri sebagai tiga kolom terpisah,
 * begitu pula TOPPING OFF 20% dan 40%. Kolom `kpr_sync` inilah yang menyatukannya: kategori
 * yang bersinkron ke grup yang sama tampil sebagai SATU kolom, dan nominalnya dijumlah.
 *
 * DIGABUNG LEWAT KOLOM BARU, BUKAN DENGAN MENGUBAH code/name. Itu bukan pilihan gaya:
 *  - BankApprovalController::isDajamPajak() menentukan BENTUK JURNAL dari teks code+name+
 *    keterangan (bukan dari flag is_dajam), dan
 *  - KprApplicationController memilih kategori pajak lewat pencocokan teks 'pajak' pada name
 *    untuk auto-isi SSP/BPHTB per bank.
 * Menormalkan nama demi "menggabungkan" akan mengubah AKUNTANSI dan mematikan auto-isi itu —
 * dua-duanya gagal diam-diam. Baris masternya sendiri juga tidak boleh dilebur: 15 baris
 * bank_retention_terms memetakan akun GL per retention_category_id.
 *
 * BACKFILL DICOCOKKAN DARI TEKS `code`, bukan daftar kode yang dipatok. Basis data yang
 * berbeda memakai penamaan yang berbeda ("TANAH MATANG 1" di satu tempat, "TANAH MATANG 20%"
 * di tempat lain); yang tidak dikenali sengaja DIBIARKAN KOSONG agar operator mengisinya
 * sendiri — menebak berarti diam-diam menaruh uang di kolom yang salah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('retention_categories', 'kpr_sync')) {
            Schema::table('retention_categories', function (Blueprint $t): void {
                $t->string('kpr_sync', 32)->nullable()->after('is_dajam');
                $t->index('kpr_sync', 'retention_cat_kpr_sync_idx');
            });
        }

        // Tanpa backfill, validasi "wajib sinkron" langsung mengunci seluruh kategori lama:
        // membuka modal Edit lalu Simpan ditolak walau operator tidak menyentuh apa pun.
        $peta = [
            'TANAH MATANG' => 'tanah_matang',
            'TOPPING OFF'  => 'topping_off',
            'PONDASI'      => 'pondasi',
            'DAJAM'        => 'dajam',
            'BAST'         => 'bast_ajb',
            'AJB'          => 'bast_ajb',
        ];

        foreach (DB::table('retention_categories')->whereNull('kpr_sync')->get(['id', 'code', 'name']) as $c) {
            $teks = strtoupper(trim((string) ($c->code ?: $c->name)));
            foreach ($peta as $kata => $grup) {
                if (str_contains($teks, $kata)) {
                    DB::table('retention_categories')->where('id', $c->id)->update(['kpr_sync' => $grup]);
                    break;
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('retention_categories', 'kpr_sync')) {
            return;
        }

        Schema::table('retention_categories', function (Blueprint $t): void {
            $t->dropIndex('retention_cat_kpr_sync_idx');
            $t->dropColumn('kpr_sync');
        });
    }
};
