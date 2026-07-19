<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UM Penjualan → penanda "sudah dikompensasi dengan AR".
 *
 * Layar UM Penjualan (AR) menarik baris dari TIGA sumber yang tabelnya berbeda-beda:
 *   - 'retensi'   → kpr_retentions      (Approval Retensi, baris DAJAM yang sudah dijurnal)
 *   - 'pencairan' → kpr_realizations    (Approval Retensi, baris pencairan yang sudah dijurnal)
 *   - 'utj_dp'    → bs_faktur_keluaran_entries (Approval UM Penjualan yang sudah dikonversi)
 * Tidak ada satu pun dari tabel itu yang boleh dititipi kolom "sudah dikompensasi" — ketiganya
 * milik alur KPR/pajak dan dipakai layar lain. Jadi penandanya ditaruh di tabel sendiri yang
 * menunjuk balik ke sumbernya (source_type + source_id).
 *
 * TIDAK memposting jurnal. Kompensasi di sini murni perpindahan status dari antrean UM Penjualan
 * ke layar Rekonsiliasi Piutang; jurnal kompensasi dibuat terpisah oleh Accounting. Karena itu
 * tabel ini tidak menyimpan journal_id.
 *
 * Nilai di-snapshot (amount) supaya angka rekonsiliasi tidak berubah diam-diam bila baris sumber
 * kelak disunting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_ar_um_compensations')) {
            return;
        }
        Schema::create('bs_ar_um_compensations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('source_type', 16);              // retensi | pencairan | utj_dp
            $t->unsignedBigInteger('source_id');
            $t->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->decimal('amount', 20, 2)->default(0);
            $t->string('note', 255)->nullable();
            $t->timestamp('compensated_at')->nullable();
            $t->foreignId('compensated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            // Satu baris sumber hanya boleh dikompensasi sekali — penjaga utama terhadap
            // klik ganda / dua petugas menekan tombol bersamaan.
            $t->unique(['source_type', 'source_id'], 'bs_ar_um_comp_source_unique');
            $t->index(['tenant_id', 'booking_id'], 'bs_ar_um_comp_tenant_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_ar_um_compensations');
    }
};
