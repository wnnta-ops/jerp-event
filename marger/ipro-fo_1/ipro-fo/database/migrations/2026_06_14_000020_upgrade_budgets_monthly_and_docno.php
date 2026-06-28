<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BC-1: jadikan Budget Control bermodel bulanan + bernomor dokumen, meniru eProperty BO.
 *   - doc_no    : nomor dokumen master budget (BC/MUP/{proj}/MM/YY/#####).
 *   - project   : kode project (BO: "JR"); opsional.
 *   - m1..m12   : nilai anggaran per bulan (Jan..Des). amount tetap = total tahunan (kompat lama).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_budgets')) {
            return;
        }
        Schema::table('bs_budgets', function (Blueprint $table): void {
            if (! Schema::hasColumn('bs_budgets', 'doc_no')) {
                $table->string('doc_no')->nullable();
            }
            if (! Schema::hasColumn('bs_budgets', 'project')) {
                $table->string('project')->nullable();
            }
            for ($m = 1; $m <= 12; $m++) {
                if (! Schema::hasColumn('bs_budgets', 'm'.$m)) {
                    $table->decimal('m'.$m, 20, 2)->default(0);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bs_budgets')) {
            return;
        }
        Schema::table('bs_budgets', function (Blueprint $table): void {
            foreach (['doc_no', 'project'] as $c) {
                if (Schema::hasColumn('bs_budgets', $c)) {
                    $table->dropColumn($c);
                }
            }
            for ($m = 1; $m <= 12; $m++) {
                if (Schema::hasColumn('bs_budgets', 'm'.$m)) {
                    $table->dropColumn('m'.$m);
                }
            }
        });
    }
};
