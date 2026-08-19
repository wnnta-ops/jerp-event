<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cluster pada antrean Posting Invoice.
 *
 * Sejak Kategori Gimmick bercluster, dua kategori boleh bernama sama — "BIAYA KPR" milik
 * cluster A dan milik cluster B — dengan akun yang berbeda. Tanpa mencatat clusternya,
 * layar Posting Invoice hanya menampilkan "BIAYA KPR" dan orang yang memposting tidak
 * punya cara melihat kategori cluster mana yang sebenarnya terpakai.
 *
 * Kolom ini adalah POTRET saat baris masuk antrean, sepola dengan kpr_biaya_key: ia
 * merekam keputusan yang diambil FO waktu itu, bukan keadaan master hari ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_ap_refund_queue', 'cluster_id')) {
            return;
        }

        Schema::table('bs_ap_refund_queue', function (Blueprint $table) {
            $table->unsignedBigInteger('cluster_id')->nullable()->after('gimmick_category_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('bs_ap_refund_queue', 'cluster_id')) {
            Schema::table('bs_ap_refund_queue', function (Blueprint $table) {
                $table->dropColumn('cluster_id');
            });
        }
    }
};
