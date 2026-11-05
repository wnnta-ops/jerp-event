<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan Budget Invoice (invoice Memo Urgent yang ditahan) kini satu approval mengikuti chain
 * Invoice Hutang — alokasi / koreksinya jadi turunan approval itu, bukan approval Finance sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_budget_requests', function (Blueprint $t): void {
            $t->unsignedBigInteger('approval_id')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_budget_requests', function (Blueprint $t): void {
            $t->dropColumn('approval_id');
        });
    }
};
