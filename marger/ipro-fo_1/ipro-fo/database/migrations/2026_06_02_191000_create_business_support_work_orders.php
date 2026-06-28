<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUSINESS SUPPORT — Contractor Management (SPK / Surat Perintah Kerja).
 * SPK ke kontraktor + progress termin (jurnal Dr CIP+PPN / Cr Hutang+Retensi) + pelunasan & pencairan retensi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_work_orders', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('spk_number', 48)->unique();
            $t->date('spk_date');
            $t->unsignedBigInteger('supplier_id');                // kontraktor
            $t->unsignedBigInteger('cost_center_id')->nullable(); // proyek / divisi
            $t->unsignedBigInteger('construction_account_id');    // CIP / beban konstruksi
            $t->string('title');
            $t->decimal('contract_value', 20, 2)->default(0);
            $t->decimal('retention_pct', 5, 2)->default(0);
            $t->decimal('ppn_pct', 5, 2)->default(0);
            $t->decimal('progress_value', 20, 2)->default(0);     // akumulasi progress (DPP)
            $t->decimal('retention_held', 20, 2)->default(0);     // retensi tertahan berjalan
            $t->string('status', 12)->default('active');          // active / closed
            $t->date('retention_released_at')->nullable();
            $t->string('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_work_order_progress', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('work_order_id')->constrained('bs_work_orders')->cascadeOnDelete();
            $t->date('progress_date');
            $t->string('description');
            $t->decimal('progress_value', 20, 2);   // DPP termin
            $t->decimal('retention_amount', 20, 2)->default(0);
            $t->decimal('ppn_amount', 20, 2)->default(0);
            $t->decimal('net_payable', 20, 2)->default(0);
            $t->string('status', 12)->default('unpaid'); // unpaid / paid
            $t->date('paid_at')->nullable();
            $t->unsignedBigInteger('journal_id')->nullable();      // akrual progress
            $t->unsignedBigInteger('pay_journal_id')->nullable();  // pelunasan
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_work_order_progress');
        Schema::dropIfExists('bs_work_orders');
    }
};
