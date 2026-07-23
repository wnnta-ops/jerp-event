<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan warkat BG/Cek keluar → Kuitansi AP yang dibuat saat PENCAIRAN.
 * Alur baru: warkat pelunasan hutang yang dicairkan TIDAK langsung melunasi AP,
 * melainkan membuat Kuitansi 'pending' (tanggal = tanggal pencairan) yang masuk
 * antrian Approval Pengeluaran Kas & Bank. Kolom ini penaut + penjaga double-create.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_outgoing_cheques', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_outgoing_cheques', 'clear_kuitansi_id')) {
                $t->unsignedBigInteger('clear_kuitansi_id')->nullable()->after('clear_journal_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_outgoing_cheques', function (Blueprint $t): void {
            if (Schema::hasColumn('bs_outgoing_cheques', 'clear_kuitansi_id')) {
                $t->dropColumn('clear_kuitansi_id');
            }
        });
    }
};
