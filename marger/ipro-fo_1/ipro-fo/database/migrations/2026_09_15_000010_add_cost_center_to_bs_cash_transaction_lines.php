<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost center per BARIS transaksi kas/bank — dimensi yang menautkan pengeluaran ke Budget Control.
 *
 * KENAPA HARUS DI BASIS DATA, bukan sekadar diteruskan dari layar ke jurnal: jurnal transaksi
 * kas TIDAK selalu dibentuk saat penyimpanan. Pengeluaran (BKK) baru berjurnal setelah disetujui,
 * dan RecordCashTransaction::postJournal() menyusun ulang barisnya DARI TABEL INI, bukan dari
 * payload yang dulu dikirim. Tanpa kolomnya, cost center yang dipilih operator akan hilang di
 * antara "Simpan" dan "Approve" — dan jurnalnya tetap balance, jadi tidak ada satu pun galat
 * yang memberi tahu; yang ketahuan belakangan hanya realisasi anggaran yang kurang.
 *
 * NULLABLE, meski di layar WAJIB diisi. Kolom ini turun ke basis data yang sudah berisi
 * transaksi lama; membuatnya NOT NULL berarti harus menebak cost center untuk baris yang memang
 * tidak pernah punya, dan tebakan itu akan terbaca sebagai realisasi anggaran cost center yang
 * sebenarnya tidak menanggungnya. Kewajibannya ditegakkan di jalur pembuatan (validasi request +
 * gerbang layar), bukan dengan memaksa data lama mengaku punya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_cash_transaction_lines', 'cost_center_id')) {
            return;
        }

        Schema::table('bs_cash_transaction_lines', function (Blueprint $t) {
            $t->foreignId('cost_center_id')->nullable()->after('account_id')
                ->constrained('bs_cost_centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_cash_transaction_lines', 'cost_center_id')) {
            return;
        }

        Schema::table('bs_cash_transaction_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cost_center_id');
        });
    }
};
