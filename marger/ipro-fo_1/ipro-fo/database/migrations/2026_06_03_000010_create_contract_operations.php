<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operasi Kontrak (eProperty SALES → Kontrak): Adjustment, Adjustment PPN,
 * Tagihan Custom, Pengalihan Hak (Ganti Nama), Pindah Unit, Pembatalan Kontrak.
 * Satu tabel audit untuk seluruh operasi; perubahan state diterapkan ke booking/kontrak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_operations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('contract_id');
            $t->unsignedBigInteger('booking_id')->nullable();
            $t->string('operation_no', 30)->unique();
            // adjustment | ppn | tagihan_custom | ganti_nama | pindah_unit | batal
            $t->string('type', 20);
            $t->text('reason')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->decimal('amount', 20, 2)->default(0); // penalti/refund/charge/kenaikan PPN
            $t->string('status', 16)->default('applied');
            $t->unsignedBigInteger('processed_by')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['contract_id', 'type']);
        });

        // Status kontrak 'cancelled' dipakai oleh Pembatalan — kolom status sudah string,
        // tidak perlu alter. Tambah kolom ringkas hasil pembatalan untuk kemudahan query.
        Schema::table('contracts', function (Blueprint $t): void {
            $t->date('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $t): void {
            $t->dropColumn('cancelled_at');
        });
        Schema::dropIfExists('contract_operations');
    }
};
