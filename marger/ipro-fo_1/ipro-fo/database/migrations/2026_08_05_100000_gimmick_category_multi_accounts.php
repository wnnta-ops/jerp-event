<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori Gimmick: BANYAK akun debit & BANYAK akun kredit + tautan ke kolom Rincian Biaya
 * pada Master Data KPR.
 *
 * Sebelumnya satu kategori hanya bisa satu akun debit dan satu akun kredit, padahal satu biaya
 * gimmick (mis. BIAYA PPJB) kerap dipecah ke beberapa akun beban / beberapa akun hutang.
 *
 * bs_gimmick_categories.debit_account_id & payable_account_id TETAP ADA dan selalu diisi akun
 * UTAMA (baris pertama tiap sisi). Semua pembaca lama — Posting Invoice, laporan — tidak perlu
 * diubah dan tetap membaca angka yang benar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_gimmick_category_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gimmick_category_id')->constrained('bs_gimmick_categories')->cascadeOnDelete();
            $t->string('side', 8);                              // debit | credit
            $t->foreignId('account_id')->constrained('bs_accounts');
            // Porsi nilai gimmick untuk akun ini (persen). Satu akun = 100. Jumlah per sisi = 100.
            $t->decimal('portion', 7, 4)->default(100);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['gimmick_category_id', 'side', 'account_id'], 'bs_gmc_cat_acc_unique');
            $t->index(['gimmick_category_id', 'side']);
        });

        Schema::table('bs_gimmick_categories', function (Blueprint $t): void {
            // Kolom Rincian Biaya pada Master Data KPR yang diisi kategori ini
            // (kpr | ht | ppjb | ssp | bphtb | ajb). Null = tidak memengaruhi Master Data KPR.
            $t->string('kpr_biaya_key', 16)->nullable()->after('name');
            $t->index(['tenant_id', 'kpr_biaya_key']);
        });

        // Backfill: akun tunggal yang sudah ada menjadi baris pertama (porsi 100%) di tiap sisi.
        $now = now();
        $rows = [];
        foreach (DB::table('bs_gimmick_categories')->get(['id', 'debit_account_id', 'payable_account_id']) as $c) {
            foreach ([['debit', $c->debit_account_id], ['credit', $c->payable_account_id]] as [$side, $accId]) {
                if ($accId) {
                    $rows[] = [
                        'gimmick_category_id' => $c->id, 'side' => $side, 'account_id' => $accId,
                        'portion' => 100, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('bs_gimmick_category_accounts')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::table('bs_gimmick_categories', function (Blueprint $t): void {
            $t->dropIndex(['tenant_id', 'kpr_biaya_key']);
            $t->dropColumn('kpr_biaya_key');
        });
        Schema::dropIfExists('bs_gimmick_category_accounts');
    }
};
