<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice Hutang: tanggal berkas fisik diterima Finance.
 *
 * SENGAJA kolom baru, bukan memakai yang sudah ada — di layar Invoice Hutang sudah ada dua
 * konsep serupa yang mudah tertukar:
 *   - receive_date  ("Tgl. Diterima")      = tagihan SUPPLIER diterima; ini yang menghitung Aging Hutang.
 *   - berkas_notes  ("Kelengkapan Berkas") = catatan bebas berkas yang masih kurang (bukan tanggal).
 * Yang ini berbeda lagi: kapan Finance menerima berkas fisiknya.
 *
 * Hanya boleh diisi oleh Finance (peran Finance / FinanceManager) — ditegakkan di
 * ApInvoiceController::updateBerkasFinance, bukan di kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bs_ap_invoices', 'berkas_finance_date')) {
            return;
        }
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            $t->date('berkas_finance_date')->nullable()->after('berkas_notes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_ap_invoices', 'berkas_finance_date')) {
            return;
        }
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            $t->dropColumn('berkas_finance_date');
        });
    }
};
