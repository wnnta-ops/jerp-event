<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kuitansi Hutang (Registrasi Pengeluaran Kas & Bank AP) kini lewat APPROVAL dahulu
 * (sejajar BKK reguler #159): registrasi → status 'pending' (tanpa jurnal) → disetujui → 'active'
 * (jurnal JAP + payment + saldo invoice terbentuk) / ditolak → 'rejected'.
 * Kolom audit approver ditambahkan; kuitansi lama tetap 'active' (tak ikut antrian).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_kuitansis', function (Blueprint $t): void {
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_kuitansis', function (Blueprint $t): void {
            $t->dropColumn(['approved_by', 'approved_at']);
        });
    }
};
