<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CM: atribut scope Project kontraktor (Contractor Management → Setup → Project).
 * Cluster/Tahap/Unit = label deskriptif ruang lingkup SPK; coa_account_id = COA
 * default (referensi bs_accounts) yang di-prefill saat Register SPK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_projects', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_projects', 'cluster')) {
                $t->string('cluster', 100)->nullable()->after('dept');
            }
            if (! Schema::hasColumn('bs_projects', 'tahap')) {
                $t->string('tahap', 100)->nullable()->after('cluster');
            }
            if (! Schema::hasColumn('bs_projects', 'unit')) {
                $t->string('unit', 100)->nullable()->after('tahap');
            }
            if (! Schema::hasColumn('bs_projects', 'coa_account_id')) {
                $t->unsignedBigInteger('coa_account_id')->nullable()->after('unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_projects', function (Blueprint $t): void {
            $t->dropColumn(['cluster', 'tahap', 'unit', 'coa_account_id']);
        });
    }
};
