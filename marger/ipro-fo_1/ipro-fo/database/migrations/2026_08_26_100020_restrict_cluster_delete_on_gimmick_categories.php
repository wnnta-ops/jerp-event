<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menghapus cluster tidak boleh diam-diam mengubah akun yang terbebani.
 *
 * bs_gimmick_categories.cluster_id semula nullOnDelete(). Artinya satu DELETE atas sebuah
 * cluster mengubah kategori "BIAYA AJB milik JRK3" menjadi "BIAYA AJB berlaku SEMUA cluster"
 * — kategori itu lalu menyerap GMC dari seluruh cluster dan membebankannya ke akun JRK3.
 * Tidak ada galat, tidak ada jejak; gejalanya baru terasa berbulan kemudian saat rekonsiliasi.
 *
 * Sejak cluster mengikuti master Lokasi FO, daur hidupnya menjadi hidup: menghapus Lokasi di
 * FO kini merambat ke BO. Sinkronnya sendiri hanya menandai terhapus dan tidak pernah DELETE,
 * tetapi pintu penghapusan manual tetap terbuka — dan pintu itu ditutup di lapisan yang tidak
 * bisa dilupakan siapa pun, yaitu MySQL.
 *
 * Harga yang dibayar dengan sadar: menghapus cluster yang masih dipakai kategori kini DITOLAK
 * sampai kategorinya dipindah lebih dulu. Penolakan yang berisik jauh lebih murah daripada
 * pembebanan akun yang salah tanpa suara.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bs_gimmick_categories', 'cluster_id')) {
            return;
        }

        Schema::table('bs_gimmick_categories', function (Blueprint $table) {
            $table->dropForeign(['cluster_id']);
            $table->foreign('cluster_id')->references('id')->on('clusters')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_gimmick_categories', 'cluster_id')) {
            return;
        }

        Schema::table('bs_gimmick_categories', function (Blueprint $table) {
            $table->dropForeign(['cluster_id']);
            $table->foreign('cluster_id')->references('id')->on('clusters')->nullOnDelete();
        });
    }
};
