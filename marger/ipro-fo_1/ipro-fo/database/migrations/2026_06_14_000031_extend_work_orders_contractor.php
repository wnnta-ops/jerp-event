<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CM-1/3/5/6: lengkapi tabel SPK & turunannya meniru eProperty BO.
 *   bs_work_orders            : project_id, jenis, est_start/end, is_pkp, releaser, pic_project, no_manual
 *   bs_work_order_progress    : kpl_number, releaser, pic, no_manual (Kontrol Progress Lapangan)
 *   bs_work_order_progress_mutations : status/approved_* (Approval Mutasi Progress Bangunan)
 *   bs_work_order_materials   : status, closed_at, returned_amount (Close SBO + Pengembalian)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_work_orders')) {
            Schema::table('bs_work_orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('bs_work_orders', 'project_id')) {
                    $table->unsignedBigInteger('project_id')->nullable();
                }
                foreach (['jenis' => 'bangunan', 'releaser' => null, 'pic_project' => null, 'no_manual' => null] as $col => $def) {
                    if (! Schema::hasColumn('bs_work_orders', $col)) {
                        $table->string($col)->nullable()->default($def);
                    }
                }
                if (! Schema::hasColumn('bs_work_orders', 'is_pkp')) {
                    $table->boolean('is_pkp')->default(true);
                }
                foreach (['est_start', 'est_end'] as $col) {
                    if (! Schema::hasColumn('bs_work_orders', $col)) {
                        $table->date($col)->nullable();
                    }
                }
            });
        }

        if (Schema::hasTable('bs_work_order_progress')) {
            Schema::table('bs_work_order_progress', function (Blueprint $table): void {
                foreach (['kpl_number', 'releaser', 'pic', 'no_manual'] as $col) {
                    if (! Schema::hasColumn('bs_work_order_progress', $col)) {
                        $table->string($col)->nullable();
                    }
                }
            });
        }

        if (Schema::hasTable('bs_work_order_progress_mutations')) {
            Schema::table('bs_work_order_progress_mutations', function (Blueprint $table): void {
                if (! Schema::hasColumn('bs_work_order_progress_mutations', 'status')) {
                    $table->string('status')->default('approved'); // baru|approved|rejected (data lama = approved)
                }
                if (! Schema::hasColumn('bs_work_order_progress_mutations', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable();
                }
                if (! Schema::hasColumn('bs_work_order_progress_mutations', 'approved_by')) {
                    $table->unsignedBigInteger('approved_by')->nullable();
                }
            });
        }

        if (Schema::hasTable('bs_work_order_materials')) {
            Schema::table('bs_work_order_materials', function (Blueprint $table): void {
                if (! Schema::hasColumn('bs_work_order_materials', 'status')) {
                    $table->string('status')->default('open'); // open|closed
                }
                if (! Schema::hasColumn('bs_work_order_materials', 'closed_at')) {
                    $table->date('closed_at')->nullable();
                }
                if (! Schema::hasColumn('bs_work_order_materials', 'returned_amount')) {
                    $table->decimal('returned_amount', 20, 2)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        // non-destructive
    }
};
