<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link berkas supplier bisa dipakai mengunggah ULANG (selama invoicenya belum disimpan): tiap
 * unggahan dicatat di riwayat (waktu, berkas, no. invoice, jumlah file) — ditampilkan di halaman
 * link & wizard. Unggahan terbaru menggantikan berkas sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_berkas_links', function (Blueprint $t): void {
            $t->json('riwayat')->nullable()->after('filled_at');
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_berkas_links', function (Blueprint $t): void {
            $t->dropColumn('riwayat');
        });
    }
};
