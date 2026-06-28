<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GL S4 — Confirm Saldo Awal (konfirmasi/kunci saldo awal) + Late Closing (penutupan terlambat).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_opening_confirmations')) {
            Schema::create('bs_opening_confirmations', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained();
                $t->integer('year');
                $t->decimal('total_debit', 20, 2)->default(0);
                $t->decimal('total_credit', 20, 2)->default(0);
                $t->boolean('balanced')->default(false);
                $t->unsignedBigInteger('confirmed_by')->nullable();
                $t->timestamp('confirmed_at')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'year']);
            });
        }

        Schema::table('bs_fiscal_periods', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_fiscal_periods', 'late_closed')) {
                $t->boolean('late_closed')->default(false);
            }
            if (! Schema::hasColumn('bs_fiscal_periods', 'late_note')) {
                $t->string('late_note')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_opening_confirmations');
        Schema::table('bs_fiscal_periods', function (Blueprint $t): void {
            if (Schema::hasColumn('bs_fiscal_periods', 'late_note')) {
                $t->dropColumn('late_note');
            }
            if (Schema::hasColumn('bs_fiscal_periods', 'late_closed')) {
                $t->dropColumn('late_closed');
            }
        });
    }
};
