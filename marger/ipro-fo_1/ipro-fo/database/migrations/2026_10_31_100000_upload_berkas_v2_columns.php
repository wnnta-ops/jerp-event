<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upload Berkas v2 — siklus dokumen lengkap (draft, revisi, batal, tutup parsial, hapus),
 * jenis transaksi, tanggal dokumen & rencana bayar, rincian pajak (subtotal, diskon, PPN),
 * cost center & akun beban (untuk cek anggaran saat input dan isian awal invoice).
 *
 * status: draft → menunggu_ttd → siap_invoice → sebagian_diinvoice → diinvoice
 *         · ditolak (bisa direvisi & diajukan ulang) · dibatalkan · ditutup (parsial)
 * amount tetap = TOTAL tagihan (setelah diskon & PPN). Satu berkas boleh punya beberapa
 * invoice (bs_ap_invoices.source_type='berkas', source_id=berkas) — ap_invoice_id = terakhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_berkas_uploads', function (Blueprint $t): void {
            if (Schema::hasColumn('bs_ap_berkas_uploads', 'jenis')) {
                return;
            }
            $t->string('jenis', 30)->nullable()->after('upload_date')->index(); // material | alat_kerja | biaya_proyek | biaya_non_proyek
            $t->date('document_date')->nullable()->after('jenis');              // tanggal dokumen / tagihan supplier
            $t->date('needed_date')->nullable()->after('document_date');        // rencana bayar / dibutuhkan
            $t->decimal('subtotal', 20, 2)->nullable()->after('supplier_invoice_no');
            $t->decimal('discount', 20, 2)->default(0)->after('subtotal');
            $t->decimal('ppn_pct', 5, 2)->default(0)->after('discount');
            $t->boolean('tax_inclusive')->default(false)->after('ppn_pct');
            $t->decimal('ppn_amount', 20, 2)->default(0)->after('tax_inclusive');
            $t->unsignedBigInteger('cost_center_id')->nullable()->after('amount');
            $t->unsignedBigInteger('expense_account_id')->nullable()->after('cost_center_id');
            $t->timestamp('submitted_at')->nullable()->after('uploaded_by');
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->string('close_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_berkas_uploads', function (Blueprint $t): void {
            $t->dropColumn([
                'jenis', 'document_date', 'needed_date', 'subtotal', 'discount', 'ppn_pct', 'tax_inclusive',
                'ppn_amount', 'cost_center_id', 'expense_account_id', 'submitted_at',
                'cancelled_at', 'cancelled_by', 'cancel_reason', 'closed_at', 'closed_by', 'close_reason',
            ]);
        });
    }
};
