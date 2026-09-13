<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DUA TABEL LAPORAN KPR PER CLUSTER — diisi lewat import Excel, bukan diturunkan dari transaksi.
 *
 * Sumbernya Exsum Collection bulanan. Sebagian kolomnya memang tidak bisa dihitung dari basis
 * data mana pun: "ESCROW / SISA RETENSI" berisi kalimat seperti "Sisa Retensi 4,2 M · MUI 5",
 * dan "LAIN-LAIN" berisi "Clear" atau "Retensi TO + BAST". Itu catatan yang ditulis orang,
 * bukan angka yang dihitung mesin — karena itu disimpan sebagai TEKS apa adanya.
 *
 * Kolom bernilai uang pada tabel Outstanding juga disimpan sebagai teks ("Rp 1,2 M (2 unit
 * RUHA)"): angkanya selalu datang bersama keterangan unitnya, dan memaksanya menjadi desimal
 * akan membuang justru bagian yang membuatnya bisa dibaca.
 *
 * `urutan` menjaga baris tampil persis seperti di Exsum — mengurutkannya menurut abjad membuat
 * "Cluster 3.10" mendahului "Cluster 3.2", dan pembacanya kehilangan alur tahapannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tabel 1 — Proses KPR per Cluster (Status Unit).
        Schema::create('kpr_cluster_status', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('urutan')->default(0);
            $t->string('label', 120);                 // "Cluster 2.2 (JRK2 T2)"
            $t->unsignedInteger('total_unit')->default(0);
            $t->unsignedInteger('realisasi')->default(0);
            $t->unsignedInteger('kpr')->default(0);
            $t->unsignedInteger('cash')->default(0);
            $t->unsignedInteger('proses')->default(0);
            $t->unsignedInteger('open_stock')->default(0);
            $t->unsignedInteger('hold')->default(0);
            $t->string('escrow', 255)->nullable();    // teks bebas
            $t->text('catatan')->nullable();          // catatan kaki tabel
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['tenant_id', 'label'], 'kpr_cluster_status_unik');
        });

        // Tabel 2 — Outstanding (O/S) KPR per Cluster & Tahap.
        Schema::create('kpr_cluster_outstanding', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('urutan')->default(0);
            $t->string('label', 120);                 // "JRK 3 — Tahap 4"
            $t->string('retensi', 255)->nullable();
            $t->string('proses', 255)->nullable();
            $t->string('open_stok', 255)->nullable();
            $t->string('lain_lain', 255)->nullable();
            $t->text('catatan')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['tenant_id', 'label'], 'kpr_cluster_os_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpr_cluster_outstanding');
        Schema::dropIfExists('kpr_cluster_status');
    }
};
