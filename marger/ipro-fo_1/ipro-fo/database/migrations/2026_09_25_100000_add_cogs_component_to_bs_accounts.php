<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Komponen COGS" pada Chart of Account.
 *
 * Akun yang ditandai salah satu komponen membuat SELURUH mutasi buku besarnya — masuk maupun
 * keluar — terbaca di layar Komponen COGS (Tanah / Housing / Infrastruktur / License Permit).
 *
 * NILAINYA MEMAKAI KUNCI KOMPONEN HPP, BUKAN SLUG URL. Yang disimpan adalah
 * 'tanah' | 'bangunan' | 'sarana' | 'permit' — sama persis dengan
 * bs_cogs_component_ap_categories.component dan dengan BsCogsCategory::COMPONENTS. Slug
 * alamatnya ('housing', 'infrastruktur', 'license-permit') sengaja TIDAK dipakai di basis
 * data: dua kosakata untuk satu hal berarti setiap pembacaan harus menerjemahkan lebih dulu,
 * dan terjemahan yang terlupa di satu tempat menghasilkan komponen yang diam-diam kosong.
 *
 * KOLOMNYA SENGAJA TANPA FOREIGN KEY. Daftar komponennya hidup di kode
 * (CogsComponentController::KOMPONEN), bukan di sebuah tabel master — sama seperti
 * bs_cogs_component_ap_categories.component yang juga varchar polos.
 *
 * Bawaannya NULL untuk seluruh akun yang sudah ada: tidak ada satu pun akun yang berubah
 * perilakunya tanpa diminta, dan layar Komponen COGS tidak bergerak sampai operator menandai
 * akunnya sendiri.
 */
return new class extends Migration
{
    private const TABEL = 'bs_accounts';

    private const KOLOM = 'cogs_component';

    private const INDEKS = 'bs_accounts_cogs_component_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABEL) || Schema::hasColumn(self::TABEL, self::KOLOM)) {
            return;
        }

        Schema::table(self::TABEL, function (Blueprint $t): void {
            $t->string(self::KOLOM, 20)->nullable()->after('cost_center_id');
        });

        // Penjaga hasIndex: tanpa ini migrasi tidak idempoten — dijalankan ulang pada basis
        // data yang indeksnya sudah ada akan berhenti dengan galat "Duplicate key name".
        if (! $this->punyaIndeks()) {
            Schema::table(self::TABEL, function (Blueprint $t): void {
                $t->index(self::KOLOM, self::INDEKS);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABEL, self::KOLOM)) {
            return;
        }

        Schema::table(self::TABEL, function (Blueprint $t): void {
            if ($this->punyaIndeks()) {
                $t->dropIndex(self::INDEKS);
            }
            $t->dropColumn(self::KOLOM);
        });
    }

    private function punyaIndeks(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS ada FROM information_schema.STATISTICS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [self::TABEL, self::INDEKS],
        ) !== null;
    }
};
