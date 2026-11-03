<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keterangan baris Realisasi Bon Sementara diperpanjang 255 → 500 karakter.
 *
 * Keterangan baris ikut disalin ke bs_journal_lines.description (jurnal realisasi), jadi
 * kolom itu ikut dilebarkan — kalau tidak, keterangan > 255 lolos validasi lalu gagal
 * saat jurnalnya dibentuk. Melebarkan VARCHAR tidak mengubah data yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_cash_advance_lines', function (Blueprint $t): void {
            $t->string('description', 500)->change();
        });
        Schema::table('bs_journal_lines', function (Blueprint $t): void {
            $t->string('description', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Tidak dipersempit kembali: keterangan > 255 yang sudah tersimpan akan terpotong.
    }
};
