<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TARGET AKAD KPR per bulan — lawan "budget" pada layar Realisasi vs Budget.
 *
 * Satu baris = satu bulan (pola yang sama dengan Cash Flow, Fiscal Period, dan Closing Credit
 * Card), bukan 12 kolom m1..m12 seperti bs_budgets. Alasannya: targetnya diisi lewat import
 * Excel baris-per-bulan dan dibaca kembali baris-per-bulan; menyimpannya sebagai 12 kolom
 * memaksa kedua sisi menerjemahkan bentuk yang tidak dipakai siapa pun.
 *
 * Unique (tenant, tahun, bulan) membuat import ulang MEMPERBARUI targetnya, bukan menumpuk
 * baris kedua — target bulan yang sama dua kali adalah dua angka yang saling membantah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpr_collection_targets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('period_year');
            $t->unsignedTinyInteger('period_month');
            // Target unit = jumlah akad. Target value = nilai plafon, rupiah penuh (BUKAN juta) —
            // layar yang menyingkatnya ke "Rp M" melakukannya saat menampilkan, supaya angka yang
            // tersimpan tidak pernah kehilangan ketelitian.
            $t->unsignedInteger('target_unit')->default(0);
            $t->decimal('target_value', 20, 2)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['tenant_id', 'period_year', 'period_month'], 'kpr_target_unik_bulan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpr_collection_targets');
    }
};
