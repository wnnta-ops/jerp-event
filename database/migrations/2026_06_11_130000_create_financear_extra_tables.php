<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance AR lengkap (selaras legacy): FP (Faktur Pajak), BG (Bilyet Giro + ganti giro),
 * Refund (booking + lebih bayar / CB), Transfer Anonim (transfer tak teridentifikasi).
 * Terintegrasi: BG/FP nyambung ke TTS; Refund ke kontrak/reservasi; Transfer Anonim → TTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        // FP — Faktur Pajak (nomor dari DJP, di-link ke TTS/kwitansi)
        Schema::create('fo_fp', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('no_fp');                       // nomor faktur pajak (eksternal)
            $table->date('tgl_terima')->nullable();
            $table->foreignId('tts_id')->nullable()->constrained('fo_tts')->nullOnDelete();
            $table->string('no_kwitansi')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('status')->default('BELUM_TERPAKAI'); // BELUM_TERPAKAI / TERPAKAI
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'no_fp']);
        });

        // BG — Bilyet Giro / Cek (diterima sebagai pembayaran)
        Schema::create('fo_bg', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('no_bg');                       // nomor giro/cek
            $table->date('tgl_bg')->nullable();
            $table->date('tgl_jatuh_tempo')->nullable();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('fo_customers')->nullOnDelete();
            $table->foreignId('tts_id')->nullable()->constrained('fo_tts')->nullOnDelete();
            $table->decimal('amount', 19, 2)->default(0);
            $table->string('pengelola')->nullable();
            $table->string('status')->default('NORMAL');   // NORMAL/JATUH_TEMPO/CAIR/BERMASALAH/DIGANTI
            $table->foreignId('bg_pengganti_id')->nullable()->constrained('fo_bg')->nullOnDelete(); // ganti giro
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
        });

        // Refund — pengembalian (booking batal / lebih bayar / CB)
        Schema::create('fo_refund', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('no_refund')->nullable();       // RF-... (numerator)
            $table->date('tgl_refund')->nullable();
            $table->string('jenis')->default('BOOKING');   // BOOKING / LEBIH_BAYAR
            $table->foreignId('customer_id')->nullable()->constrained('fo_customers')->nullOnDelete();
            $table->foreignId('kontrak_id')->nullable()->constrained('fo_kontrak')->nullOnDelete();
            $table->foreignId('reservasi_id')->nullable()->constrained('fo_reservasi')->nullOnDelete();
            $table->decimal('nilai_kotor', 19, 2)->default(0);
            $table->decimal('biaya_admin', 19, 2)->default(0);
            $table->decimal('nilai_refund', 19, 2)->default(0);
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('rekening_tujuan')->nullable();
            $table->string('status')->default('DRAFT');    // DRAFT/DIPROSES/SELESAI/BATAL
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'no_refund']);
        });

        // Transfer Anonim — transfer masuk yang belum teridentifikasi
        Schema::create('fo_transfer_anonim', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->date('tgl_transfer')->nullable();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->decimal('amount', 19, 2)->default(0);
            $table->string('keterangan_bank')->nullable(); // berita/referensi dari bank
            $table->foreignId('customer_id')->nullable()->constrained('fo_customers')->nullOnDelete();
            $table->foreignId('tts_id')->nullable()->constrained('fo_tts')->nullOnDelete();
            $table->string('status')->default('BARU');     // BARU/TERIDENTIFIKASI/SELESAI
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_transfer_anonim');
        Schema::dropIfExists('fo_refund');
        Schema::dropIfExists('fo_bg');
        Schema::dropIfExists('fo_fp');
    }
};
