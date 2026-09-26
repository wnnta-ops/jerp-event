<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COGS Baru — Perhitungan COGS, Set Up Kategori, Actual Costing, dan Rincian COGS per unit.
 *
 * Mencerminkan lampiran "MODUL HPP.xlsx" apa adanya. Lima tabel, semuanya bergantung pada satu
 * PERIODE (lokasi + tahap), karena tiap lembar di lampiran memang berjudul "LOKASI ….. TAHAP …..".
 *
 * ── Mengapa berdiri sendiri, tidak menumpang tabel COGS yang lama ──
 * COGS lama (bs_cogs_budgets, bs_cogs_hpp, …) berbutir (tahun, proyek, lokasi, kategori, akun)
 * dan mengalokasikan ke unit menurut HARGA. Model baru ini mengalokasikan menurut LUAS, punya
 * konsep "sudah/belum booked" beserta pilihan costing per unit, dan punya rantai perhitungan
 * melingkar yang tidak ada di model lama. Menumpangkannya akan memaksa kedua model saling
 * mengompromikan, dan angka HPP yang berbeda akan lahir dari dua jalur tanpa ada yang tahu
 * mana yang benar. User pun memintanya sebagai modul terpisah: "COGS Lama" dan "COGS Baru".
 *
 * ── Yang DISIMPAN hanya yang diketik orang ──
 * Seluruh kolom BUDGET (J..N) dan REALISASI (O..S) pada lembar Rincian COGS adalah RUMUS. Tidak
 * satu pun disimpan di sini: angka turunan yang disimpan bisa berbeda dari rumusnya tanpa ada
 * yang memberi tahu, dan justru angka itulah yang berakhir di HPP per unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Periode: satu lembar kerja = satu lokasi + tahap ──────────────────────────
        if (! Schema::hasTable('bs_cogs2_periode')) {
            Schema::create('bs_cogs2_periode', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();

                $table->unsignedBigInteger('lokasi_id');
                $table->string('lokasi_kode', 32)->nullable();
                $table->string('lokasi_nama', 150)->nullable();
                // '' = seluruh tahap. NOT NULL supaya kunci unik benar-benar menahan yang kembar:
                // MySQL menganggap setiap NULL berbeda, sehingga dua periode untuk cakupan yang
                // sama akan lolos dan melahirkan dua angka HPP per unit.
                $table->string('tahap', 32)->default('');

                // Blok "Perhitungan Lahan" — semuanya DIKETIK. Jalan (= Lahan − Saleable − RTH)
                // dan seluruh persentase diturunkan, tidak disimpan.
                $table->decimal('luas_lahan', 14, 2)->default(0);
                $table->decimal('saleable', 14, 2)->default(0);
                $table->decimal('rth', 14, 2)->default(0);
                $table->decimal('luas_bangunan', 14, 2)->default(0);
                $table->unsignedInteger('jumlah_unit')->nullable();

                $table->text('catatan')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'lokasi_id', 'tahap'], 'cogs2_periode_unik');
            });
        }

        // ── Tipe rumah beserta luas bangunannya (blok atas SET UP KATEGORI COGS-HOUSING) ──
        // Hanya NAMA dan LUAS BANGUNAN yang diketik; jumlah unit, budget per unit, budget per
        // meter, dan seluruh angka realisasinya DITURUNKAN.
        if (! Schema::hasTable('bs_cogs2_housing_tipe')) {
            Schema::create('bs_cogs2_housing_tipe', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('periode_id')->index();
                $table->string('nama', 100);
                $table->decimal('luas_bangunan', 10, 2)->default(0);
                $table->unsignedSmallInteger('urut')->default(0);
                $table->timestamps();
            });
        }

        // ── Set Up Kategori COGS — baris budget keempat komponen ──────────────────────
        // Satu tabel untuk empat komponen karena bentuknya nyaris sama; kolom yang tidak dipakai
        // sebuah komponen dibiarkan NULL. Memecahnya menjadi empat tabel menghasilkan empat
        // pengendali dan empat layar yang isinya sama, dan perbedaan kecil di antaranya akan
        // menjadi sumber selisih yang tak pernah terlihat.
        if (! Schema::hasTable('bs_cogs2_kategori')) {
            Schema::create('bs_cogs2_kategori', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('periode_id')->index();
                $table->string('komponen', 12);            // tanah|housing|infra|license
                $table->string('lokasi', 32)->nullable();
                $table->string('tahap', 32)->nullable();
                $table->string('kategori', 100)->nullable();   // tipe rumah / URUGAN / SERTIFIKASI …
                $table->string('no_spk', 150)->nullable();

                // Khusus TANAH: nominal = luas_tanah × harga_rata2 (rumus F3 di lampiran).
                $table->decimal('luas_tanah', 14, 2)->nullable();
                $table->decimal('harga_rata2', 18, 6)->nullable();

                $table->decimal('nominal', 20, 2)->default(0);
                $table->unsignedSmallInteger('urut')->default(0);
                $table->timestamps();

                $table->index(['periode_id', 'komponen'], 'cogs2_kategori_periode_komponen');
            });
        }

        // ── Actual Costing COGS — baris jurnal realisasi keempat komponen ─────────────
        if (! Schema::hasTable('bs_cogs2_actual')) {
            Schema::create('bs_cogs2_actual', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('periode_id')->index();
                $table->string('komponen', 12);            // tanah|housing|infra|license
                $table->string('lokasi', 32)->nullable();
                $table->string('tahap', 32)->nullable();
                $table->string('kategori', 100)->nullable();
                $table->string('no_spk', 150)->nullable();
                $table->string('no_jurnal', 100)->nullable();
                $table->string('keterangan', 255)->nullable();

                // Penambahan & pengurangan DIPISAH, tidak disimpan sebagai satu angka bersih:
                // saldo akhir = penambahan − pengurangan adalah turunan, dan satu angka bersih
                // menyembunyikan koreksi yang justru perlu terlihat saat menelusuri selisih.
                $table->decimal('penambahan', 20, 2)->default(0);
                $table->decimal('pengurangan', 20, 2)->default(0);
                $table->unsignedSmallInteger('urut')->default(0);
                $table->timestamps();

                $table->index(['periode_id', 'komponen'], 'cogs2_actual_periode_komponen');
            });
        }

        // ── Rincian COGS — satu baris per unit ────────────────────────────────────────
        if (! Schema::hasTable('bs_cogs2_unit')) {
            Schema::create('bs_cogs2_unit', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('periode_id')->index();

                $table->string('no_spr', 64)->nullable();
                $table->string('nama_customer', 150)->nullable();
                $table->string('unit_rumah', 64);
                $table->string('lokasi', 32)->nullable();
                $table->string('tahap', 32)->nullable();
                $table->string('tipe', 100)->nullable();       // menautkan ke bs_cogs2_housing_tipe.nama
                $table->decimal('lt', 10, 2)->default(0);
                $table->decimal('lb', 10, 2)->default(0);

                // 'belum' | 'sudah'. Menentukan apakah unit ini masih ikut menanggung realisasi.
                $table->string('status', 12)->default('belum');
                // 'belum' | 'standard' | 'realisasi'. Hanya unit SUDAH BOOKED + STANDARD COSTING
                // yang berhenti menanggung realisasi; sisanya tetap ikut.
                $table->string('costing', 12)->default('belum');

                $table->decimal('booked_revenue', 20, 2)->nullable();
                $table->unsignedSmallInteger('urut')->default(0);
                $table->timestamps();

                $table->unique(['periode_id', 'unit_rumah'], 'cogs2_unit_unik');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cogs2_unit');
        Schema::dropIfExists('bs_cogs2_actual');
        Schema::dropIfExists('bs_cogs2_kategori');
        Schema::dropIfExists('bs_cogs2_housing_tipe');
        Schema::dropIfExists('bs_cogs2_periode');
    }
};
