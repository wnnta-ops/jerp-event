<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanggal Finance menerima berkas REALISASI — terpisah dari berkas pengajuan.
 *
 * Satu bon melewati dua kali serah-terima berkas:
 *
 *   received_document_date            berkas PENGAJUAN diterima Finance (sebelum dibayar)
 *   realisasi_document_date  (baru)   berkas REALISASI diterima Finance (sesudah dipakai)
 *
 * Keduanya dipakai mengukur hal yang berbeda: yang pertama lama Finance memproses
 * pembayaran, yang kedua lama pemegang bon mempertanggungjawabkan pemakaiannya. Dipakaikan
 * satu kolom untuk dua peristiwa akan menimpa yang pertama begitu yang kedua diisi, dan
 * umur berkas pengajuan hilang tanpa jejak.
 *
 * Diisi DEPT FINANCE, bukan pengaju — merekalah yang menerima fisik berkasnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t) {
            $t->date('realisasi_document_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t) {
            $t->dropColumn('realisasi_document_date');
        });
    }
};
