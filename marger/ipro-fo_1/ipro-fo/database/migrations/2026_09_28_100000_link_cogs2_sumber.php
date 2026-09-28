<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actual Costing COGS mendapat DUA sumber otomatis, selain baris yang diketik sendiri:
 *
 *   1. KONTRAK SPK (MUK) yang ditautkan lewat tombol "Link COGS"
 *   2. MUTASI BUKU BESAR pada akun COA yang ditandai Komponen COGS di Tambah/Edit Akun
 *
 * ── Mengapa akun COA harus DIPILIH per periode, bukan ikut semuanya ──
 * Tanda Komponen COGS melekat pada AKUN, dan bagan akun di pemasangan ini memisahkan cluster di
 * tingkat akun: 10104010131 "CIP TANAH C3 KRIAN" dan 10104010136 "CIP TANAH C5 SUKODONO"
 * keduanya bertanda Tanah. Menarik seluruh akun bertanda Tanah ke sebuah periode berarti
 * membebankan biaya cluster lain ke unit cluster ini — angkanya tetap keluar, tetap terlihat
 * wajar, dan tidak ada satu pun galat. Karena itu tiap periode memilih sendiri akun mana yang
 * miliknya.
 *
 * ── Mengapa kedua sumber punya saklar ──
 * Baris yang diketik operator bisa saja SUDAH memuat kontrak atau jurnal yang sama. Menjumlahkan
 * keduanya diam-diam menggandakan biaya aktual, dan penggandaan itu tidak melanggar satu pun
 * aturan: totalnya tetap merekonsiliasi, hanya nilainya dua kali lipat. Saklarnya menyala secara
 * bawaan — modul ini baru dan kosong, dan justru itulah gunanya ditautkan — tetapi tiap sumber
 * tampil dengan subtotalnya sendiri supaya penggandaan terlihat seketika.
 *
 * ── Mengapa kategori disimpan pada tautan MUK ──
 * Di Actual Costing, Housing/Infrastruktur/License Permit berkolom Kategori, dan realisasi
 * Housing dicocokkan lewat (nomor SPK + kategori). Tanpa kategori pada tautannya, kontrak yang
 * ditarik ke sana tidak akan pernah cocok dengan baris Set Up Kategori mana pun, dan nilainya
 * berhenti di nol tanpa keterangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Kategori pada tautan Kontrak SPK (MUK) → Komponen/Actual Costing COGS.
        if (Schema::hasTable('bs_muk_contract_cogs') && ! Schema::hasColumn('bs_muk_contract_cogs', 'kategori')) {
            Schema::table('bs_muk_contract_cogs', function (Blueprint $table): void {
                $table->string('kategori', 100)->nullable()->after('component');
            });
        }

        // Saklar kedua sumber, per periode.
        if (Schema::hasTable('bs_cogs2_periode')) {
            Schema::table('bs_cogs2_periode', function (Blueprint $table): void {
                if (! Schema::hasColumn('bs_cogs2_periode', 'ikut_muk')) {
                    $table->boolean('ikut_muk')->default(true)->after('jumlah_unit');
                }
                if (! Schema::hasColumn('bs_cogs2_periode', 'ikut_gl')) {
                    $table->boolean('ikut_gl')->default(true)->after('ikut_muk');
                }
            });
        }

        // Akun COA bertanda komponen yang diakui milik sebuah periode.
        if (! Schema::hasTable('bs_cogs2_akun')) {
            Schema::create('bs_cogs2_akun', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('periode_id')->index();
                $table->unsignedBigInteger('account_id');
                $table->timestamps();

                // Satu akun hanya boleh sekali per periode: dua baris yang sama membuat mutasi
                // buku besarnya terhitung dua kali.
                $table->unique(['periode_id', 'account_id'], 'cogs2_akun_unik');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cogs2_akun');

        if (Schema::hasTable('bs_cogs2_periode')) {
            Schema::table('bs_cogs2_periode', function (Blueprint $table): void {
                foreach (['ikut_muk', 'ikut_gl'] as $kolom) {
                    if (Schema::hasColumn('bs_cogs2_periode', $kolom)) {
                        $table->dropColumn($kolom);
                    }
                }
            });
        }

        if (Schema::hasTable('bs_muk_contract_cogs') && Schema::hasColumn('bs_muk_contract_cogs', 'kategori')) {
            Schema::table('bs_muk_contract_cogs', function (Blueprint $table): void {
                $table->dropColumn('kategori');
            });
        }
    }
};
