<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cluster pada Kategori Gimmick.
 *
 * Satu jenis biaya gimmick yang sama — "BIAYA KPR", "BIAYA HT" — dibebankan ke akun yang
 * BERBEDA per cluster (mis. PENYELESAIAN BIAYA KONSTRUKSI JRK CLUSTER 3 KRIAN), sementara
 * di Master Data KPR semuanya tetap bermuara ke SATU kolom Rincian Biaya. Sebelum ini
 * kode kategori unik per tenant, sehingga "BIAYA KPR" hanya bisa ada sekali dan akunnya
 * terpaksa dipukul rata untuk seluruh cluster.
 *
 * Karena itu keunikannya dilonggarkan menjadi per (tenant, cluster, kode): tiap cluster
 * boleh punya "BIAYA KPR" sendiri dengan akun sendiri.
 *
 * cluster_id SENGAJA boleh kosong dan itulah nilai awal seluruh kategori yang sudah ada.
 * Kategori tanpa cluster berlaku untuk SEMUA cluster — ia menjadi cadangan saat sebuah
 * cluster belum punya kategori sendiri. Tanpa perilaku ini, migrasi akan mematikan
 * seluruh kategori yang berjalan sekarang begitu FO mulai mengirimkan cluster.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_gimmick_categories', 'cluster_id')) {
            return;
        }

        Schema::table('bs_gimmick_categories', function (Blueprint $table) {
            $table->foreignId('cluster_id')->nullable()->after('tenant_id')
                ->constrained('clusters')->nullOnDelete();
        });

        Schema::table('bs_gimmick_categories', function (Blueprint $table) {
            $table->dropUnique('bs_gimmick_categories_tenant_id_code_unique');
            $table->unique(['tenant_id', 'cluster_id', 'code'], 'bs_gimmick_categories_tenant_cluster_code_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_gimmick_categories', 'cluster_id')) {
            return;
        }

        Schema::table('bs_gimmick_categories', function (Blueprint $table) {
            $table->dropUnique('bs_gimmick_categories_tenant_cluster_code_unique');
            $table->dropForeign(['cluster_id']);
            $table->dropColumn('cluster_id');
        });

        // Dikembalikan hanya bila memang tidak ada kode kembar — kategori per cluster yang
        // sudah terlanjur dibuat akan menabrak keunikan lama, dan menghapusnya diam-diam
        // jauh lebih merugikan daripada meninggalkan indeksnya longgar.
        $kembar = Schema::hasTable('bs_gimmick_categories')
            ? \Illuminate\Support\Facades\DB::table('bs_gimmick_categories')
                ->select('tenant_id', 'code')->groupBy('tenant_id', 'code')
                ->havingRaw('COUNT(*) > 1')->exists()
            : false;

        if (! $kembar) {
            Schema::table('bs_gimmick_categories', function (Blueprint $table) {
                $table->unique(['tenant_id', 'code'], 'bs_gimmick_categories_tenant_id_code_unique');
            });
        }
    }
};
