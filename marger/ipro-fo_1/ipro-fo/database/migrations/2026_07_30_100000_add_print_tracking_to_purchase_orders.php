<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak cetak Purchase Order + simpanan format cetaknya.
 *
 * - printed_at / print_count / printed_by : dibutuhkan Report PO ("waktu cetak PO"), diisi saat
 *   operator benar-benar menekan Cetak — bukan sekadar membuka pratinjau.
 * - print_meta (JSON)                     : hasil edit format cetak (kop, alamat, penerima, catatan,
 *   kondisi pembelian, penanda tangan, dst). Disimpan sebagai JSON agar format PO bisa diedit &
 *   dicetak ulang tanpa menambah belasan kolom yang hanya dipakai untuk pencetakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_purchase_orders', function (Blueprint $table) {
            $table->timestamp('printed_at')->nullable()->after('approved_at');
            $table->unsignedInteger('print_count')->default(0)->after('printed_at');
            $table->unsignedBigInteger('printed_by')->nullable()->after('print_count');
            $table->json('print_meta')->nullable()->after('printed_by');
        });
    }

    public function down(): void
    {
        Schema::table('bs_purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['printed_at', 'print_count', 'printed_by', 'print_meta']);
        });
    }
};
