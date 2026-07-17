<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUSINESS SUPPORT — Aset Tetap (Fixed Asset) + Penyusutan (garis lurus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_fixed_assets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('asset_number', 48)->unique();
            $t->string('name');
            $t->string('category', 20);                          // bangunan / kendaraan / inventaris / tanah
            $t->date('acquisition_date');
            $t->decimal('acquisition_cost', 20, 2);
            $t->decimal('residual_value', 20, 2)->default(0);    // nilai sisa
            $t->unsignedInteger('useful_life_months')->default(0);
            $t->string('method', 16)->default('straight_line');
            $t->unsignedBigInteger('asset_account_id');
            $t->unsignedBigInteger('accum_account_id')->nullable();
            $t->unsignedBigInteger('expense_account_id')->nullable();
            $t->decimal('accumulated_depreciation', 20, 2)->default(0);
            $t->date('last_depreciation_date')->nullable();
            $t->string('status', 16)->default('active');         // active / fully_depreciated / disposed
            // Catatan: 16 ternyata kurang 1 karakter untuk 'fully_depreciated' (17) —
            // diperlebar ke 24 oleh 2026_07_16_150000_widen_fixed_asset_status.
            $t->unsignedBigInteger('acquire_journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_depreciation_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('fixed_asset_id')->constrained('bs_fixed_assets')->cascadeOnDelete();
            $t->unsignedSmallInteger('period_year');
            $t->unsignedTinyInteger('period_month');
            $t->date('entry_date');
            $t->decimal('amount', 20, 2);
            $t->decimal('book_value_after', 20, 2);
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->timestamps();

            $t->unique(['fixed_asset_id', 'period_year', 'period_month'], 'bs_depr_period_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_depreciation_entries');
        Schema::dropIfExists('bs_fixed_assets');
    }
};
