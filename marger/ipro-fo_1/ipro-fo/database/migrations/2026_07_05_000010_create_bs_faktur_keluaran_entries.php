<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entri Faktur Pajak Keluaran yang lahir dari Approval Retensi & Approval UTJ & DP.
 * Saat approval disetujui, selain posting GL, satu baris entri dibuat di sini agar
 * muncul di Verifikasi Faktur Pajak (PPN Keluaran) untuk diisi Nomor Faktur Pajak.
 *
 *  - source_type = 'retensi'  → langsung aktif (boleh isi nomor FP).
 *  - source_type = 'utj_dp'   → aktif setelah tombol "Konversi ke Uang Muka Penjualan".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_faktur_keluaran_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('source_type', 16);            // 'retensi' | 'utj_dp'
            $t->unsignedBigInteger('source_id')->nullable(); // kpr_retentions.id | tts_receipts.id
            $t->string('keterangan', 100)->nullable();       // 'Retensi' | 'UTJ & DP'
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('booking_id')->nullable(); // resolusi unit untuk report per-unit
            $t->date('doc_date')->nullable();                 // tanggal jurnal/approval
            $t->decimal('dpp', 20, 2)->default(0);
            $t->decimal('ppn', 20, 2)->default(0);
            $t->decimal('total', 20, 2)->default(0);
            $t->string('fp_number', 30)->nullable();          // Nomor Faktur Pajak (NSFP) — editable
            $t->boolean('is_active')->default(false);         // input nomor FP aktif?
            $t->timestamp('converted_at')->nullable();        // waktu konversi utj_dp → uang muka
            $t->unsignedBigInteger('convert_journal_id')->nullable(); // jurnal reklasifikasi konversi
            $t->unsignedBigInteger('journal_id')->nullable(); // jurnal approval sumber (link GL)
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['source_type', 'source_id']);         // idempoten per sumber approval
            $t->index(['tenant_id', 'source_type']);
            $t->index('booking_id');
            $t->index('doc_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_faktur_keluaran_entries');
    }
};
