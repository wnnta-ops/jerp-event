<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upload Berkas (Account Payable › Master) — titik awal approval hutang.
 *
 *   staf unggah berkas tagihan → TTD Dept Head yang dipilih (approval 'ap_berkas')
 *   → tim AP membuat Invoice Hutang dari berkas → approval 'ap_invoice'
 *     (Accounting → Finance → GM → Direktur → MW / BA) → baru boleh dibayar.
 *
 * status: menunggu_ttd → siap_invoice → diinvoice   (atau ditolak di TTD Dept Head)
 * Nomor: BRK-{USER}-{MMYY}-{NNNN}, urut per pengunggah per bulan.
 * File di disk privat 'local' (ap-berkas/{tenant}/{id}/…), jenisnya dari master bs_ap_berkas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_ap_berkas_uploads')) {
            Schema::create('bs_ap_berkas_uploads', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->nullable()->index();
                $t->string('berkas_number', 60)->unique();
                $t->date('upload_date');
                $t->unsignedBigInteger('supplier_id')->nullable()->index();
                $t->string('supplier_invoice_no', 80)->nullable();
                $t->decimal('amount', 20, 2)->nullable();
                $t->string('description', 255);
                $t->unsignedBigInteger('dept_head_id')->index();   // penanda tangan pertama
                $t->string('status', 20)->default('menunggu_ttd')->index();
                $t->unsignedBigInteger('approval_id')->nullable()->index();    // approval TTD Dept Head
                $t->unsignedBigInteger('ap_invoice_id')->nullable()->index();  // invoice yang dibuat
                $t->unsignedBigInteger('uploaded_by')->index();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (! Schema::hasTable('bs_ap_berkas_upload_files')) {
            Schema::create('bs_ap_berkas_upload_files', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('berkas_upload_id')->index();
                $t->unsignedBigInteger('berkas_id')->nullable();   // jenis berkas (bs_ap_berkas)
                $t->string('original_name');
                $t->string('path');
                $t->string('mime', 100);
                $t->unsignedBigInteger('size');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_ap_berkas_upload_files');
        Schema::dropIfExists('bs_ap_berkas_uploads');
    }
};
