<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set Up COGS — "Perhitungan Lahan" per lokasi/tahap, beserta komponen biayanya.
 *
 * Inilah rumah bagi blok kiri lampiran Excel (Luas Lahan, Saleable, RTH, Jalan, jumlah Unit)
 * dan keempat pos biayanya (LAC Tanah, Infrastruktur, Undertable, License Permit). Sebelum ini
 * tidak ada satu kolom pun di ipro_bo maupun jerp yang bisa menampungnya.
 *
 * ── Mengapa tabel BARU, bukan menumpang bs_cogs_provisions atau bs_cogs_budgets ──
 * bs_cogs_budgets berbutir (tahun, proyek, lokasi, kategori, akun) dengan SATU kolom amount —
 * tidak ada tempat untuk luas, tarif, maupun hasil per unit; nilainya pun diubah di tempat oleh
 * Approval Mutasi Budget, sehingga menulis ulang ke sana akan menghapus mutasi yang sudah
 * disetujui tanpa jejak. bs_cogs_provisions hanya pemetaan akun per proyek.
 *
 * ── Mengapa kuncinya lokasi FO + tahap, bukan project_id ──
 * Luas tanah & luas bangunan per unit hanya hidup di `jerp`.fo_units, yang berkunci lokasi_id
 * dan tahap. Memakai kunci lain memaksa penerjemahan di tengah perhitungan — dan di modul ini
 * sudah ada preseden buruknya: CogsService::generateHpp menyaring units.cluster_id dengan id
 * yang berasal dari bs_cogs_locations, dua ruang id yang tidak berhubungan.
 *
 * ── Mengapa `tahap` NOT NULL default '' ──
 * '' berarti "seluruh lokasi". Bila kolomnya nullable, MySQL menganggap setiap NULL berbeda,
 * sehingga kunci unik (tenant_id, lokasi_id, tahap) TIDAK akan menahan dua set-up "seluruh
 * lokasi" untuk lokasi yang sama — dan dua set-up untuk cakupan yang sama menghasilkan dua
 * budget per unit yang berbeda tanpa satu pun galat.
 *
 * ── Mengapa nilai komponen decimal(20,2) ──
 * Lampiran memuat 10.255.943.066,221262 — presisi yang lahir dari rantai rumus antarbuku kerja,
 * bukan dari rupiah yang benar-benar ada. Rupiah adalah satuan pembukuan di sini, jadi angkanya
 * dibulatkan ke sen dan selisih Rp 0,001262 itu memang dibuang.
 *
 * ── Mengapa daftar komponen berupa TABEL, bukan empat kolom ──
 * Rumus totalnya di lampiran adalah SUM(D10:D14) — rentangnya sengaja melewati satu baris kosong
 * supaya komponen kelima bisa disisipkan. Empat kolom tetap akan memaksa migrasi baru setiap kali
 * ada pos biaya baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_cogs_setup')) {
            Schema::create('bs_cogs_setup', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();

                // Cakupan: lokasi FO (jerp.fo_lokasi.id) + tahap. Kode & nama ikut disalin supaya
                // layar tetap terbaca saat FO tak terjangkau.
                $table->unsignedBigInteger('lokasi_id');
                $table->string('lokasi_kode', 32)->nullable();
                $table->string('lokasi_nama', 150)->nullable();
                $table->string('tahap', 32)->default('');   // '' = seluruh lokasi

                // Perhitungan Lahan. Saleable & RTH DIKETIK (di lampiran keduanya konstanta);
                // Jalan dan seluruh persentase DITURUNKAN, jadi tidak disimpan — menyimpannya
                // membuat jumlahnya bisa melenceng dari Luas Lahan tanpa ada yang menolak simpan.
                $table->decimal('luas_lahan', 14, 2)->default(0);
                $table->decimal('saleable', 14, 2)->default(0);
                $table->decimal('rth', 14, 2)->default(0);

                // Keterangan saja — di lampiran, angka Unit (199) tidak dipakai rumus mana pun.
                // Disimpan supaya bisa diadu dengan jumlah unit yang benar-benar terbaca dari FO.
                $table->unsignedInteger('jumlah_unit')->nullable();

                // Pembagi komponen berdasar TANAH: 'saleable' (angka yang diketik, persis
                // lampiran) atau 'unit' (Σ luas tanah unit, menjamin Σ bagian = total).
                $table->string('dasar_tanah', 12)->default('saleable');

                // Apakah fo_units.luas_lebih_tanah ikut dihitung sebagai luas tanah unit.
                // Bawaannya TIDAK: luas_tanah adalah kolom utamanya, dan menambahkan kelebihan
                // tanah yang ternyata sudah termasuk di dalamnya akan menghitungnya dua kali.
                // Layar menampilkan Σ luas tanah kedua-duanya supaya setelan yang benar terlihat.
                $table->boolean('termasuk_lebih_tanah')->default(false);

                $table->text('catatan')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'lokasi_id', 'tahap'], 'cogs_setup_lokasi_tahap_unik');
            });
        }

        if (! Schema::hasTable('bs_cogs_setup_components')) {
            Schema::create('bs_cogs_setup_components', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('setup_id')->index();
                $table->string('nama', 100);

                // Dasar pembagi: 'tanah' (per m2 tanah) atau 'bangunan' (per m2 bangunan).
                // Inilah yang membuat satu modul bisa mengakomodir keduanya seperti diminta;
                // keempat pos di lampiran semuanya berdasar tanah.
                $table->string('dasar', 12)->default('tanah');

                $table->decimal('nilai', 20, 2)->default(0);
                $table->unsignedSmallInteger('urut')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cogs_setup_components');
        Schema::dropIfExists('bs_cogs_setup');
    }
};
