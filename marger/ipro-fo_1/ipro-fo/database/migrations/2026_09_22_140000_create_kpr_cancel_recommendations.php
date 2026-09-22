<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "Memo Rekom Batal sudah dibuat untuk SPR ini".
 *
 * Inilah satu-satunya hal yang menyeberang dari modul KPR (Back Office) ke tombol
 * "Ajukan Pembatalan" pada Pembatalan Kontrak (Marketing & Sales, aplikasi Front Office):
 * selama barisnya belum ada, tombol di sana terkunci.
 *
 * KENAPA TABEL SENDIRI, BUKAN KOLOM PADA kpr_initiations / kpr_applications:
 *
 *  1. kpr_initiations.stages TIDAK BOLEH DIPAKAI. IproMasterSync::syncKpr menimpa SELURUH
 *     kolom itu pada setiap sinkron dari FO — penanda apa pun yang dititipkan di sana lenyap
 *     diam-diam pada sinkron berikutnya, dan tombolnya terkunci kembali tanpa jejak.
 *  2. Penandanya lahir dari DUA sumber (inisiasi untuk Reject BI Checking, pengajuan untuk
 *     Reject Proses KPR). Ditaruh di masing-masing tabelnya, sisi FO harus menanyai dua tempat
 *     dan menggabungkannya sendiri — satu query yang terlupa berarti separuh SPR tetap terkunci.
 *  3. Yang dibaca FO adalah NOMOR SPR, bukan id BO. Menyimpannya di sini (spr_number) membuat
 *     sisi FO cukup satu query `whereIn('spr_number', …)` tanpa join lintas-database.
 *
 * spr_number sengaja DIDUPLIKASI dari bookings.booking_number (yang diisi IproMasterSync dari
 * fo_kontrak.no_kontrak): itulah satu-satunya identitas yang dikenali KEDUA aplikasi, dan ia
 * tidak boleh ikut berubah kalau baris booking-nya kelak dibangun ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kpr_cancel_recommendations')) {
            return;
        }

        Schema::create('kpr_cancel_recommendations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('booking_id')->constrained();
            // Nomor SPR sebagaimana dikenal Front Office (fo_kontrak.no_kontrak).
            $t->string('spr_number', 64);
            // 'reject_bi' (dari inisiasi) atau 'reject_kpr' (dari pengajuan).
            $t->string('source', 16);
            $t->unsignedBigInteger('source_id');
            $t->string('reason')->nullable();
            $t->timestamp('recommended_at');
            $t->unsignedBigInteger('recommended_by')->nullable();
            $t->string('recommended_by_name')->nullable();
            $t->timestamps();

            // Satu SPR = satu penanda. Rekomendasi kedua (mis. dari sumber lain, atau memo
            // dicetak ulang) memperbarui baris yang sama, bukan menumpuk baris kembar yang
            // membuat "sudah direkomendasikan?" punya lebih dari satu jawaban.
            $t->unique(['tenant_id', 'spr_number'], 'kpr_cancel_rec_spr_unik');
            $t->index('booking_id', 'kpr_cancel_rec_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpr_cancel_recommendations');
    }
};
