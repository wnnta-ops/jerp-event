<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor cetak SIP: NNN/FIN/MITRA-SI/{bulan romawi}/{tahun}.
 *
 * MENGAPA DISIMPAN, BUKAN DIHITUNG SAAT MENCETAK.
 * Nomor ini melekat pada dokumen instruksi bayar yang sudah dipegang bank dan penyetuju.
 * Kalau diturunkan dengan menghitung "SIP ke berapa di tahun ini", satu SIP baru yang
 * tanggalnya mundur akan MENGGESER nomor semua dokumen yang sudah tercetak — dua kertas
 * dengan nomor sama, atau nomor yang berubah saat dicetak ulang. Karena itu nomornya
 * ditetapkan sekali lalu tidak pernah berubah.
 *
 * print_year disimpan terpisah supaya urutannya bisa dikunci per tahun tanpa menghitung
 * ulang dari sip_date setiap kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_sips', function (Blueprint $table) {
            if (! Schema::hasColumn('bs_sips', 'print_seq')) {
                $table->unsignedInteger('print_seq')->nullable()->after('sip_number');
            }
            if (! Schema::hasColumn('bs_sips', 'print_year')) {
                $table->unsignedSmallInteger('print_year')->nullable()->after('print_seq');
            }
        });

        // Satu nomor per (tenant, tahun) — penjaga terakhir bila dua pencetakan beradu.
        if (Schema::hasColumn('bs_sips', 'print_seq') && Schema::hasColumn('bs_sips', 'print_year')) {
            Schema::table('bs_sips', function (Blueprint $table) {
                $table->unique(['tenant_id', 'print_year', 'print_seq'], 'bs_sips_print_no_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('bs_sips', function (Blueprint $table) {
            $table->dropUnique('bs_sips_print_no_unique');
            $table->dropColumn(['print_seq', 'print_year']);
        });
    }
};
