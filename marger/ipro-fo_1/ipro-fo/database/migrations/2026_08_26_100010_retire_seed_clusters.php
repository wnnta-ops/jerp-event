<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pensiunkan cluster contoh "Cluster A / B / C".
 *
 * Ketiganya lahir dari ProjectSeeder, bukan dari FO. Selama mereka ada, pilihan Cluster pada
 * Setup Kategori Gimmick menawarkan tiga cluster yang tidak pernah ada di Master Unit — dan
 * kategori yang telanjur ditautkan ke salah satunya tidak akan pernah cocok saat Posting
 * Invoice dari FO, karena FO hanya mengenal Lokasi yang sesungguhnya.
 *
 * Ditandai terhapus, BUKAN dihapus:
 *   - units.cluster_id memakai RESTRICT dan 18 unit contoh masih menunjuknya, jadi DELETE
 *     akan ditolak MySQL;
 *   - membereskan 18 unit itu pekerjaan merusak yang tidak diperlukan hanya untuk merapikan
 *     sebuah daftar pilihan;
 *   - baris yang ditandai masih bisa dibaca kalau ternyata ada yang merujuknya, sehingga
 *     tidak ada label yang mendadak kosong.
 *
 * Klausa NOT IN adalah syarat mutlak: kalau sebuah cluster contoh terlanjur dipakai kategori
 * gimmick, memensiunkannya diam-diam akan membuat kategori itu tampil sebagai "berlaku semua
 * cluster" padahal cluster_id-nya terisi — kategori akan terbaca menyerap seluruh cluster,
 * sementara posting justru tidak pernah menemukannya lagi.
 */
return new class extends Migration
{
    private const KODE = ['C-A', 'C-B', 'C-C'];

    public function up(): void
    {
        if (! Schema::hasTable('clusters') || ! Schema::hasColumn('clusters', 'fo_lokasi_id')) {
            return;
        }

        $dipakai = Schema::hasTable('bs_gimmick_categories')
            ? DB::table('bs_gimmick_categories')->whereNotNull('cluster_id')->distinct()->pluck('cluster_id')->all()
            : [];

        DB::table('clusters')
            ->whereIn('code', self::KODE)
            ->whereNull('fo_lokasi_id')
            ->whereNull('deleted_at')
            ->when($dipakai !== [], fn ($q) => $q->whereNotIn('id', $dipakai))
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('clusters') || ! Schema::hasColumn('clusters', 'fo_lokasi_id')) {
            return;
        }

        DB::table('clusters')
            ->whereIn('code', self::KODE)
            ->whereNull('fo_lokasi_id')
            ->update(['deleted_at' => null, 'updated_at' => now()]);
    }
};
