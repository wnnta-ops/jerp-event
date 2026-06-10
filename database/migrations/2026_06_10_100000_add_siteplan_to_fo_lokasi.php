<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siteplan PER-CLUSTER (Lokasi). Tiap Lokasi (mis. JRK3) punya gambar siteplan
 * + setelan tampilan sendiri. Kosong → fallback ke siteplan project (lihat
 * Lokasi::resolvedSiteplan()). Koordinat unit (fo_units.siteplan_coords) tetap
 * per-unit; saat cluster pakai gambar sendiri, koordinat digambar ulang via
 * Polygon Editor terhadap gambar cluster tsb.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_lokasi', function (Blueprint $table) {
            $table->string('siteplan_image_path')->nullable()->after('nama');
            $table->integer('siteplan_width')->nullable()->after('siteplan_image_path');
            $table->integer('siteplan_height')->nullable()->after('siteplan_width');
            $table->boolean('siteplan_show_info')->default(true)->after('siteplan_height');
            $table->boolean('siteplan_show_status')->default(true)->after('siteplan_show_info');
        });
    }

    public function down(): void
    {
        Schema::table('fo_lokasi', function (Blueprint $table) {
            $table->dropColumn([
                'siteplan_image_path', 'siteplan_width', 'siteplan_height',
                'siteplan_show_info', 'siteplan_show_status',
            ]);
        });
    }
};
