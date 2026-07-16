<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bs_fixed_assets.status: varchar(16) → varchar(24).
 *
 * BUG NYATA: nilai 'fully_depreciated' panjangnya 17 karakter, sedangkan kolomnya dibuat
 * varchar(16) — meleset satu karakter (2026_06_02_180000...:32, yang komentarnya justru
 * menyebut "active / fully_depreciated / disposed"). Akibatnya SETIAP aset yang mencapai
 * akhir masa manfaat gagal disimpan:
 *
 *   SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'status'
 *
 * Terkena di dua tempat pada FixedAssetService: planDepreciation() saat sisa nilai habis, dan
 * recordDepreciation() saat akumulasi mencapai dasar penyusutan. Sejak jurnal penyusutan massal
 * digabung dalam SATU transaksi, satu aset yang habis susut membatalkan SELURUH run bulanan —
 * jadi bug lama ini sekarang berdampak jauh lebih luas dan harus ditutup.
 *
 * 24 karakter memberi ruang untuk seluruh status yang dipakai (active, fully_depreciated,
 * sold, disposed) beserta cadangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_fixed_assets', function (Blueprint $t): void {
            $t->string('status', 24)->default('active')->change();
        });
    }

    public function down(): void
    {
        // Persempit lagi hanya bila tidak ada baris yang akan terpotong.
        Schema::table('bs_fixed_assets', function (Blueprint $t): void {
            $t->string('status', 16)->default('active')->change();
        });
    }
};
