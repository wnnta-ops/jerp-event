<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan KPR (Finance) — dasar dari SPR. Menampung biaya proses KPR
 * (HT sebagian, KPR, notaris, sisa HT) + pencairan KPR. Saat diajukan,
 * kontrak SPR ditandai status_kpr = HAK_TANGGUNGAN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_kpr_pengajuan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('no_pengajuan', 32)->unique();
            $table->unsignedBigInteger('kontrak_id');
            $table->date('tgl_pengajuan');
            // Catatan: Pencairan KPR (pelunasan baris PENCAIRAN KPR) ditangani modul
            // BankLoan, BUKAN di sini.
            $table->string('status', 16)->default('DIAJUKAN');           // DIAJUKAN | SELESAI | BATAL
            $table->text('keterangan')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'kontrak_id']);
        });

        Schema::create('fo_kpr_pengajuan_biaya', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pengajuan_id');
            // HT_SEBAGIAN | KPR (cepat) · NOTARIS | SISA_HT (menyusul) · FREE (gimmick SPR)
            $table->string('jenis', 16);
            $table->string('nama')->nullable();          // label tampil (mis. "FREE BPHTB")
            $table->decimal('nominal', 18, 2)->default(0);
            $table->string('status', 16)->default('PENDING');            // PENDING | TERPROSES
            $table->date('tgl_proses')->nullable();
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->string('no_referensi', 64)->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->index('pengajuan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_kpr_pengajuan_biaya');
        Schema::dropIfExists('fo_kpr_pengajuan');
    }
};
