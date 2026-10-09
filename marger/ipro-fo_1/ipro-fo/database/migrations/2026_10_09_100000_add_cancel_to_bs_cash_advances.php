<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembatalan Bon Sementara — bon yang DITOLAK SEBELUM DIBAYAR.
 *
 * ── KENAPA STATUS, BUKAN HAPUS ──
 *
 * Pengajuan yang ditolak tetap pernah terjadi: ada yang mengajukan, ada yang menolak, dan
 * keduanya perlu bisa ditelusuri. Menghapus barisnya membuat nomor bon melompat tanpa
 * penjelasan, dan orang yang mencarinya menyimpulkan sistemnya kehilangan data.
 *
 * ── TIDAK ADA JURNAL YANG PERLU DIBALIK ──
 *
 * Pembatalan hanya boleh selagi status masih 'requested' — belum dibayar Finance, jadi belum
 * ada satu pun jurnal yang lahir dari bon ini. Bon yang sudah dibayar TIDAK dibatalkan lewat
 * jalur ini: uangnya sudah keluar, dan yang berlaku adalah Refund/Reimburse.
 *
 * ── ALASAN WAJIB, DAN DISIMPAN ──
 *
 * Penolakan tanpa alasan memaksa pengaju bertanya lewat jalur lain, dan jawabannya tidak
 * tercatat di mana pun. Kolomnya nullable hanya karena baris lama tidak punya nilainya;
 * jalur pembatalan menuntutnya terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t) {
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->string('cancel_reason', 255)->nullable();

            // Dipakai layar riwayat & penyaring antrean pembayaran: bon batal harus hilang
            // dari antrean Finance, bukan menggantung di sana selamanya.
            $t->index(['tenant_id', 'cancelled_at'], 'bs_cash_adv_cancel_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bs_cash_advances', function (Blueprint $t) {
            $t->dropIndex('bs_cash_adv_cancel_idx');
            $t->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason']);
        });
    }
};
