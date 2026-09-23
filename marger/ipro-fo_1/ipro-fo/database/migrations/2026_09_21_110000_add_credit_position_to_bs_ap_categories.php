<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posisi kredit pada Kategori Invoice (AP): Hutang atau Beban Masih Harus Dibayar.
 *
 * Nilainya menentukan akun yang dikredit saat invoice dijurnal.
 *
 * CATATAN (diperbarui 23 September 2026): saat migrasi ini ditulis, kolom ini TIDAK dibaca
 * layar mana pun — kalimat aslinya berbunyi "seluruh layar dan laporan Account Payable tidak
 * satu pun berubah". Itu tidak berlaku lagi. Kedelapan layar Query Account Payable kini punya
 * penyaring Hutang Usaha (AP) / Biaya yang Masih Harus Dibayar (BYMHD) / Tampilkan Semua, dan
 * kolom inilah sumber kebenarannya (lihat App\Domain\BusinessSupport\Support\PosisiHutang).
 *
 * Artinya mengubah posisi sebuah kategori kini MEMINDAHKAN invoice-invoice lamanya antar
 * kolom laporan, bukan sekadar mengubah penjurnalan invoice berikutnya.
 *
 * KENAPA AKUNNYA TETAP DISIMPAN DI payable_account_id, bukan dibiarkan kosong dan
 * disimpulkan dari posisi: seluruh hilir membaca kolom itu — RegisterApInvoice membekukannya
 * ke header invoice, dan pelunasan (PayApInvoice, ApKuitansiService, ApMemorialService)
 * mendebit kembali akun di header. Menyimpulkan akun di tempat lain berarti ada dua sumber
 * yang bisa berbeda, dan invoice akan mengkredit satu akun lalu didebit di akun lain —
 * kedua jurnalnya tetap balance, tidak ada galat, dan salah satu akun tidak pernah nol.
 *
 * Bawaannya 'hutang' untuk seluruh 56 kategori yang sudah ada: tidak ada satu pun yang
 * berubah perilakunya tanpa diminta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_ap_categories', 'credit_position')) {
            return;
        }

        Schema::table('bs_ap_categories', function (Blueprint $t): void {
            $t->string('credit_position', 16)->default('hutang')->after('payable_account_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_ap_categories', 'credit_position')) {
            return;
        }

        Schema::table('bs_ap_categories', function (Blueprint $t): void {
            $t->dropColumn('credit_position');
        });
    }
};
