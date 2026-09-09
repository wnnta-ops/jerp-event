<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLOSING CREDIT CARD per kartu per bulan — sekaligus asal-usul "Saldo Awal" bulan berikutnya.
 *
 * KENAPA SNAPSHOT-NYA DISIMPAN, bukan dihitung ulang seperti saldo buku besar. Saldo kartu
 * bukan turunan jurnal: sejak pembayaran Debit VA tidak berjurnal, satu-satunya jejaknya adalah
 * baris pembayaran bon dan baris alokasi. Keduanya masih bisa berubah (bon dibatalkan, alokasi
 * dihapus), sehingga menghitung ulang saldo bulan lampau akan diam-diam mengubah angka yang
 * sudah pernah dilaporkan. Closing membekukan keempat angkanya, dan sisa saldo yang dibekukan
 * itulah yang menjadi Saldo Awal bulan sesudahnya.
 *
 * Selama sebuah bulan BELUM di-closing, Saldo Awalnya nol — bukan diam-diam mewarisi angka
 * berjalan bulan sebelumnya. Itu memang yang diminta: saldo awal berasal dari saldo akhir bulan
 * sebelumnya APABILA sudah closing.
 *
 * Sengaja TIDAK memakai bs_fiscal_periods: penguncian ini milik kartu, bukan milik buku besar,
 * dan menutup satu kartu tidak boleh ikut menutup periode akuntansinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_credit_card_closings')) {
            return;
        }

        Schema::create('bs_credit_card_closings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->default(1)->index();
            $t->foreignId('credit_card_id')->constrained('bs_credit_cards')->cascadeOnDelete();
            $t->unsignedSmallInteger('period_year');
            $t->unsignedTinyInteger('period_month');
            $t->decimal('saldo_awal', 20, 2)->default(0);
            $t->decimal('penambahan', 20, 2)->default(0);
            $t->decimal('pemakaian', 20, 2)->default(0);
            $t->decimal('sisa_saldo', 20, 2)->default(0);
            $t->string('note', 255)->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();

            // Satu kartu hanya boleh punya satu closing per bulan — tanpa ini, dua penutupan
            // yang berjalan bersamaan meninggalkan dua "saldo akhir" untuk bulan yang sama.
            $t->unique(['tenant_id', 'credit_card_id', 'period_year', 'period_month'], 'bs_cc_closing_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_credit_card_closings');
    }
};
