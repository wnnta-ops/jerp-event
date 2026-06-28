<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Release retensi parsial — lacak akumulasi yang sudah dilepas agar bisa dilepas bertahap
 * (status: held → partial → released). released_amount=0 = belum, =amount = lunas dilepas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_retentions', function (Blueprint $table) {
            if (! Schema::hasColumn('kpr_retentions', 'released_amount')) {
                $table->decimal('released_amount', 20, 2)->default(0);
            }
        });

        // Backfill: retensi yang sudah berstatus 'released' dianggap sudah lepas penuh.
        Schema::hasColumn('kpr_retentions', 'released_amount')
            && \DB::table('kpr_retentions')->where('status', 'released')->update(['released_amount' => \DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('kpr_retentions', function (Blueprint $table) {
            if (Schema::hasColumn('kpr_retentions', 'released_amount')) {
                $table->dropColumn('released_amount');
            }
        });
    }
};
