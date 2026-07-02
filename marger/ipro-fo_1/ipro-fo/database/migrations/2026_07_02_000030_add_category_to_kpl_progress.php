<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori KPL (housing / infra / lain_lain) + kolom rincian untuk item pekerjaan (INFRA/LAIN-LAIN).
 * HOUSING memakai unit_id + unit_code (unit rumah). INFRA/LAIN memakai line_label/satuan/volume (BoQ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_work_order_progress', function (Blueprint $table): void {
            if (! Schema::hasColumn('bs_work_order_progress', 'category')) {
                $table->string('category', 20)->default('housing')->after('description');
            }
        });

        Schema::table('bs_work_order_progress_units', function (Blueprint $table): void {
            if (! Schema::hasColumn('bs_work_order_progress_units', 'line_label')) {
                $table->string('line_label', 200)->nullable()->after('unit_code'); // uraian pekerjaan (INFRA/LAIN)
            }
            if (! Schema::hasColumn('bs_work_order_progress_units', 'satuan')) {
                $table->string('satuan', 30)->nullable()->after('line_label');
            }
            if (! Schema::hasColumn('bs_work_order_progress_units', 'volume')) {
                $table->decimal('volume', 18, 2)->nullable()->after('satuan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_work_order_progress', function (Blueprint $table): void {
            if (Schema::hasColumn('bs_work_order_progress', 'category')) {
                $table->dropColumn('category');
            }
        });

        Schema::table('bs_work_order_progress_units', function (Blueprint $table): void {
            foreach (['line_label', 'satuan', 'volume'] as $col) {
                if (Schema::hasColumn('bs_work_order_progress_units', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
