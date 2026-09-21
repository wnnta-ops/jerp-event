<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "Lanjut Realisasikan Bon" pada bon sementara.
 *
 * Alokasi Kasbon kini berdiri sebagai sub-modul sendiri (Cash Book → Transaksi Bon Sementara →
 * Alokasi Bon Sementara), dan realisasi TIDAK boleh bisa dijalankan sebelum tombol "Lanjut
 * Realisasikan Bon" ditekan di sana.
 *
 * KENAPA KOLOM BARU, BUKAN MEMAKAI status:
 * bs_cash_advances.status hanya punya tiga nilai (requested / outstanding / realized), dan
 * nilai 'outstanding' itu dipakai SERENTAK sebagai syarat boleh-alokasi
 * (CashAdvanceAllocationService::pastikanBolehDialokasikan) dan syarat boleh-realisasi
 * (CashAdvanceService::realize). Menyisipkan nilai keempat di antaranya akan merembet ke
 * sembilan tempat yang membandingkan status, termasuk daftar & laporan.
 *
 * TIDAK ADA BACKFILL yang menandai bon lama sebagai "sudah lanjut". Bon Debit VA yang sedang
 * berjalan memang harus melewati langkah barunya — satu klik — dan menandainya otomatis di sini
 * berarti gerbang yang baru saja diminta tidak pernah berlaku untuk mereka.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_cash_advances', 'allocation_done_at')) {
            return;
        }

        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            $t->timestamp('allocation_done_at')->nullable()->after('received_document_date');
            $t->foreignId('allocation_done_by')->nullable()->after('allocation_done_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_cash_advances', 'allocation_done_at')) {
            return;
        }

        Schema::table('bs_cash_advances', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('allocation_done_by');
            $t->dropColumn('allocation_done_at');
        });
    }
};
