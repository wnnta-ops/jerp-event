<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas cluster terhadap master Lokasi di FO.
 *
 * Cluster di BO bukan master miliknya sendiri — ia cerminan master Lokasi di FO
 * (Setup Unit → Lokasi), yang di seluruh kode FO memang disebut "cluster": lihat
 * BuildsStockGrid::clusterOptions() dan PilihUnit ("Daftar cluster = master Lokasi").
 *
 * Sebelum ini jembatan memakai code = 'FOL-{lokasi_id}' sebagai kunci sinkron. Satu kolom
 * merangkap dua tugas yang saling bertentangan:
 *   - kunci mesin, yang WAJIB tidak pernah berubah supaya bs_gimmick_categories.cluster_id
 *     tidak putus;
 *   - label yang dibaca operator, yang JUSTRU harus ikut berubah saat kode/nama Lokasi
 *     diperbaiki di FO.
 * Selama merangkap, BO terkunci menampilkan "FOL-1" — string yang tidak pernah muncul di
 * layar FO mana pun, sehingga pilihan Cluster tidak bisa dicocokkan dengan Master Unit.
 *
 * Kolom fo_lokasi_id memisahkan keduanya: id Lokasi tidak bisa diubah operator, sedangkan
 * code & name bebas mengikuti FO tanpa memutus satu pun tautan.
 *
 * SENGAJA tanpa foreign key: fo_lokasi hidup di database FO yang berbeda (jerp vs ipro_bo),
 * jadi FK lintas-database mustahil. Preseden yang sama sudah dipakai bs_ap_refund_queue.cluster_id.
 *
 * Baris warisan hasil sinkron lama (code 'FOL-{n}') DIADOPSI, bukan dibiarkan menjadi kembar:
 * tanpa backfill ini, sinkron berikutnya akan membuat baris kedua untuk Lokasi yang sama dan
 * kategori gimmick lama akan menunjuk cluster yang tidak lagi dipakai siapa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clusters', 'fo_lokasi_id')) {
            return;
        }

        Schema::table('clusters', function (Blueprint $table) {
            $table->unsignedBigInteger('fo_lokasi_id')->nullable()->after('project_id')
                ->comment('id fo_lokasi di FO — kunci sinkron yang tidak pernah berubah');
        });

        // Adopsi baris warisan. CAST menghasilkan 0 untuk sisa yang tidak berangka; NULLIF
        // membuangnya supaya tidak ada cluster yang mengaku sebagai lokasi id 0.
        DB::table('clusters')
            ->where('code', 'like', 'FOL-%')
            ->whereNull('fo_lokasi_id')
            ->update([
                'fo_lokasi_id' => DB::raw("NULLIF(CAST(SUBSTRING(code, 5) AS UNSIGNED), 0)"),
            ]);

        // Jaring pengaman idempotensi di lapisan DB, bukan sekadar disiplin di kode: satu
        // Lokasi tidak akan pernah punya dua cluster walaupun dua job berjalan bersamaan.
        // MySQL mengizinkan NULL berulang, jadi cluster non-FO tidak terhalang.
        Schema::table('clusters', function (Blueprint $table) {
            $table->unique(['tenant_id', 'fo_lokasi_id'], 'clusters_tenant_fo_lokasi_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clusters', 'fo_lokasi_id')) {
            return;
        }

        Schema::table('clusters', function (Blueprint $table) {
            $table->dropUnique('clusters_tenant_fo_lokasi_unique');
            $table->dropColumn('fo_lokasi_id');
        });
    }
};
