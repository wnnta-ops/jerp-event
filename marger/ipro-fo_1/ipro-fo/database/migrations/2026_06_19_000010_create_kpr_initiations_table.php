<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inisiasi KPR — pipeline pra-kualifikasi 7 tahap sebelum aplikasi KPR resmi:
 * KUITANSI → SPRS → BI CHECKING → COLLECT BERKAS → ANALISA → PROFILING → KPR APPLICATIONS.
 * Sumber awal = Kuitansi (Finance). Tahap akhir men-generate Draft KprApplication (kpr_application_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpr_initiations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('initiation_number', 64)->nullable()->unique();
            $t->foreignId('kuitansi_id')->nullable()->constrained('kuitansis')->nullOnDelete();
            $t->foreignId('customer_id')->constrained('customers');
            $t->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $t->foreignId('bank_partner_id')->nullable()->constrained('bank_partners')->nullOnDelete();
            $t->decimal('plafon_requested', 20, 2)->nullable();
            $t->unsignedSmallInteger('current_stage')->default(1);   // 1..7
            $t->string('status', 24)->default('in_progress');         // in_progress | completed
            $t->json('stages')->nullable();                           // data per tahap
            $t->foreignId('kpr_application_id')->nullable()->constrained('kpr_applications')->nullOnDelete();
            $t->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['tenant_id', 'status']);
            $t->index('customer_id');
            $t->index('kpr_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpr_initiations');
    }
};
