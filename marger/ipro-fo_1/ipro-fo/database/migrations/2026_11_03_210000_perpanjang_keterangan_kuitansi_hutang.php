<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keterangan Kuitansi Hutang 255 → TEXT.
 *
 * Keterangan terisi otomatis dari gabungan deskripsi invoice yang dicentang; deskripsi
 * invoice SPK ±98 karakter, jadi 3 invoice sudah melewati 255 dan kuitansi tak bisa disimpan.
 * Deskripsi jurnal (bs_journals, 255) tidak dilebarkan — saat approve dipotong di service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_kuitansis', function (Blueprint $t): void {
            $t->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Tidak dipersempit kembali: keterangan > 255 yang sudah tersimpan akan terpotong.
    }
};
