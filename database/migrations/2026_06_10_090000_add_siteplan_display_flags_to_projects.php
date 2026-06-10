<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setelan tampilan siteplan publik (diatur admin di Upload Site Plan):
 *  - siteplan_show_info   : tampilkan panel Informasi (statistik Ready/Sold/Hold)
 *  - siteplan_show_status : tampilkan status (stempel SOLD/BOOKED + pill di popup)
 * Default true. /siteplan publik baca ini; pengunjung tak melihat checklist-nya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('siteplan_show_info')->default(true)->after('siteplan_height');
            $table->boolean('siteplan_show_status')->default(true)->after('siteplan_show_info');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['siteplan_show_info', 'siteplan_show_status']);
        });
    }
};
