<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pilihan "Sinkron ke Master Data KPR" pada Kategori Gimmick.
 *
 * Sebelumnya satu-satunya penanda adalah kpr_biaya_key terisi atau tidak, sehingga
 * operator tidak punya cara menghentikan sementara sinkronisasi tanpa menghapus
 * pemetaan kolomnya — dan tidak ada tempat menyatakan niat itu secara eksplisit.
 *
 * Nilai awal SENGAJA mengikuti keadaan yang sudah berjalan: kategori yang sudah
 * terpetakan langsung aktif sinkronnya, sehingga migrasi ini tidak mengubah perilaku
 * satu pun kategori yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_gimmick_categories', 'sync_kpr')) {
            return;
        }

        Schema::table('bs_gimmick_categories', function (Blueprint $table) {
            $table->boolean('sync_kpr')->default(false)->after('kpr_biaya_key');
        });

        // Kategori yang sudah punya pemetaan kolom memang selama ini dianggap sinkron.
        DB::table('bs_gimmick_categories')->whereNotNull('kpr_biaya_key')->update(['sync_kpr' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('bs_gimmick_categories', 'sync_kpr')) {
            Schema::table('bs_gimmick_categories', function (Blueprint $table) {
                $table->dropColumn('sync_kpr');
            });
        }
    }
};
