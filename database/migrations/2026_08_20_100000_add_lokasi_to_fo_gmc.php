<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cluster pada GMC.
 *
 * Di FO, cluster adalah master **Lokasi** (fo_lokasi) — itulah yang selama ini dicerminkan
 * ke BO sebagai `clusters` oleh IproMasterSync (kode `FOL-{id}`). Karena itu kolomnya
 * bernama lokasi_id, bukan cluster_id: ia menunjuk master FO yang sesungguhnya, bukan
 * nama tabel milik BO. Di layar tetap dilabeli "Cluster", mengikuti sebutan yang dipakai
 * sehari-hari.
 *
 * Gunanya: Kategori Gimmick di BO kini bercluster, sehingga biaya yang sama bisa jatuh ke
 * akun berbeda tergantung clusternya. Tanpa kolom ini, FO tidak punya cara memberi tahu BO
 * cluster mana yang dimaksud saat Posting BO, dan BO akan selalu memakai kategori cadangan.
 *
 * Nilai awal diisi dari unit pada SPR/kontraknya — sumber yang sama dengan yang dipakai
 * layar untuk mengusulkan clusternya — supaya GMC yang sudah ada tidak perlu disunting satu
 * per satu. Yang tidak bisa ditelusuri dibiarkan kosong, dan kosong berarti "pakai kategori
 * cadangan", persis perilaku sebelum kolom ini ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fo_gmc') || Schema::hasColumn('fo_gmc', 'lokasi_id')) {
            return;
        }

        Schema::table('fo_gmc', function (Blueprint $t): void {
            $t->unsignedBigInteger('lokasi_id')->nullable()->after('customer_id');
            $t->index('lokasi_id');
        });

        if (Schema::hasTable('fo_kontrak') && Schema::hasTable('fo_units')) {
            DB::statement('
                UPDATE fo_gmc g
                JOIN fo_kontrak k ON k.id = g.kontrak_id
                JOIN fo_units u ON u.id = k.unit_id
                SET g.lokasi_id = u.lokasi_id
                WHERE g.lokasi_id IS NULL AND u.lokasi_id IS NOT NULL
            ');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fo_gmc') && Schema::hasColumn('fo_gmc', 'lokasi_id')) {
            Schema::table('fo_gmc', function (Blueprint $t): void {
                $t->dropIndex(['lokasi_id']);
                $t->dropColumn('lokasi_id');
            });
        }
    }
};
