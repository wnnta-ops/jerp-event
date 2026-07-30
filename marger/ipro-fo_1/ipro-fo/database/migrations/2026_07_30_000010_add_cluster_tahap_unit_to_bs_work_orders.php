<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CM: simpan scope Cluster/Tahap/Unit di tiap SPK (bs_work_orders) — di-prefill
 * dari master Project saat Register SPK, boleh di-override, dan dipakai untuk
 * merangkai otomatis "Uraian Pekerjaan" (title) bila dikosongkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_work_orders', 'cluster')) {
                $t->string('cluster', 100)->nullable()->after('title');
            }
            if (! Schema::hasColumn('bs_work_orders', 'tahap')) {
                $t->string('tahap', 100)->nullable()->after('cluster');
            }
            if (! Schema::hasColumn('bs_work_orders', 'unit')) {
                $t->string('unit', 100)->nullable()->after('tahap');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            $t->dropColumn(['cluster', 'tahap', 'unit']);
        });
    }
};
