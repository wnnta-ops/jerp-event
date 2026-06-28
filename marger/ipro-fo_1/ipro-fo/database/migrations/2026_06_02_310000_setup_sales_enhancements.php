<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul SETUP SALES (eProperty "adminjual") — penyempurnaan Skema Cara Bayar
 * agar faithful dengan builder eProperty: Rumus Global, Rumus Nilai, Rumus Jadwal.
 * Plus History Price List (log perubahan harga unit).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Skema Cara Bayar: kolom "Rumus Global / Nilai / Jadwal" ──
        Schema::table('payment_schemes', function (Blueprint $t): void {
            // Rumus Global
            $t->boolean('present_value')->default(false);   // hitung Present Value
            $t->json('tiered_discounts')->nullable();        // diskon bertingkat [%]
            $t->decimal('booking_fee', 20, 2)->default(0);
            $t->string('bf_treatment', 20)->default('tidak');  // dp1|angsuran1|dp_disebar|angsuran_disebar|tidak
            // Rumus Nilai — DP (n × value)
            $t->unsignedSmallInteger('dp_count')->default(1);
            $t->string('dp_basis', 8)->default('percent');         // percent|amount
            $t->decimal('dp_value', 20, 2)->default(0);
            // Rumus Nilai — Angsuran (n × value)
            $t->unsignedSmallInteger('inst_count')->default(0);
            $t->string('inst_basis', 8)->default('percent');     // percent|amount
            $t->decimal('inst_value', 20, 2)->default(0);
            // Rumus Jadwal — Pertama + Interval (Bln/Hari)
            $t->unsignedSmallInteger('schedule_first')->default(1);  // jatuh tempo pertama
            $t->unsignedSmallInteger('schedule_interval')->default(1);
            $t->string('schedule_unit', 5)->default('month'); // month|day
            $t->text('notes')->nullable();
        });

        // ── History Price List — log perubahan harga unit ──
        Schema::create('sales_price_histories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('unit_id');
            $t->unsignedBigInteger('pricelist_id')->nullable();
            $t->decimal('old_price', 20, 2)->default(0);
            $t->decimal('new_price', 20, 2)->default(0);
            $t->string('source', 24)->default('manual'); // manual|pricelist|import
            $t->string('note')->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->timestamps();
            $t->index(['unit_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_price_histories');
        Schema::table('payment_schemes', function (Blueprint $t): void {
            $t->dropColumn([
                'present_value', 'tiered_discounts', 'booking_fee', 'bf_treatment',
                'dp_count', 'dp_basis', 'dp_value', 'inst_count', 'inst_basis', 'inst_value',
                'schedule_first', 'schedule_interval', 'schedule_unit', 'notes',
            ]);
        });
    }
};
