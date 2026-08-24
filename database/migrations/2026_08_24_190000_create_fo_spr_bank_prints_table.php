<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan cetak SPR Bank — supaya bisa dipantau siapa saja yang berkasnya sudah
 * dicetak untuk pengajuan ke bank.
 *
 * SATU baris per pendaftaran, bukan satu baris per klik: berkas yang sama boleh
 * dicetak ulang berkali-kali (kertas rusak, revisi isian, permintaan bank), dan
 * bila tiap klik menambah baris, laporannya berisi puluhan entri untuk satu
 * customer dan justru menutupi yang belum pernah dicetak. Yang perlu dipantau
 * adalah "siapa yang sudah", bukan "berapa kali kertas keluar" — meski jumlahnya
 * tetap dicatat pada `jumlah_cetak` kalau suatu saat diperlukan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_spr_bank_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('nup_registration_id')->constrained('fo_nup_registrations')->cascadeOnDelete();
            $table->string('no_surat', 64);
            $table->unsignedInteger('jumlah_cetak')->default(1);
            // Isi halaman apa adanya saat tombol Cetak ditekan. Sebagian kolom
            // (Blok, LT/LB, Harga Mengikat, Nama Bank, angsuran) diisi tangan di
            // layar dan tidak ada di tabel mana pun; tanpa menyimpannya, Preview
            // hanya bisa membangun ulang formulir kosong — bukan lembar yang
            // benar-benar diserahkan ke bank.
            $table->longText('isi_html')->nullable();
            $table->timestamp('pertama_cetak_at')->nullable();
            $table->timestamp('terakhir_cetak_at')->nullable();
            $table->foreignId('terakhir_cetak_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // TenantAwareModel memakai SoftDeletes — tanpa kolom ini setiap query model
            // gagal dengan "Unknown column deleted_at".
            $table->softDeletes();

            $table->unique(['tenant_id', 'nup_registration_id'], 'spr_bank_prints_reg_unique');
            $table->index(['tenant_id', 'terakhir_cetak_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_spr_bank_prints');
    }
};
