<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kelengkapan Berkas di rincian Invoice Hutang bisa menerima file.
 *  - ap_invoice_id + uploaded_by: file ditambahkan dari rincian invoice itu (hanya file ini yang boleh dihapus di sana).
 *  - berkas_upload_id boleh kosong: invoice tanpa berkas (invoice lama / posting FO) menyimpan filenya sebagai lampiran invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_berkas_upload_files', function (Blueprint $t): void {
            $t->unsignedBigInteger('berkas_upload_id')->nullable()->change();
            $t->unsignedBigInteger('ap_invoice_id')->nullable()->index()->after('berkas_upload_id');
            $t->unsignedBigInteger('uploaded_by')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_berkas_upload_files', function (Blueprint $t): void {
            $t->dropIndex(['ap_invoice_id']);
            $t->dropColumn(['ap_invoice_id', 'uploaded_by']);
        });
    }
};
