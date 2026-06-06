<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            // Verification / UTJ payment flow (NUP → Verifikasi Data).
            // Kept separate from `status` (launching-day flow) on purpose.
            $table->string('verif_status', 24)->default('MENUNGGU_BAYAR')->after('status');
            $table->decimal('utj_amount', 18, 2)->default(0)->after('booking_fee_paid');

            // Xendit invoice tracking
            $table->string('xendit_invoice_id', 64)->nullable()->after('utj_amount');
            $table->string('xendit_invoice_url', 512)->nullable()->after('xendit_invoice_id');
            $table->string('xendit_status', 16)->nullable()->after('xendit_invoice_url'); // PENDING/PAID/EXPIRED
            $table->dateTime('xendit_paid_at')->nullable()->after('xendit_status');

            // Verification audit
            $table->foreignId('verified_by')->nullable()->after('xendit_paid_at')->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable()->after('verified_by');
            $table->boolean('is_manual_approve')->default(false)->after('verified_at');
            $table->text('verif_note')->nullable()->after('is_manual_approve');

            $table->index(['tenant_id', 'verif_status']);
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'verif_status']);
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'verif_status', 'utj_amount',
                'xendit_invoice_id', 'xendit_invoice_url', 'xendit_status', 'xendit_paid_at',
                'verified_at', 'is_manual_approve', 'verif_note',
            ]);
        });
    }
};
