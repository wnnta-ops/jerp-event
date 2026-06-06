<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perluas tabel fo_units dengan atribut lengkap yang diisi dari Excel import
 * (Tahap, Alamat, Price List, Zoning, dimensi, Arah Hadap, Panorama, dst.) +
 * kolom `siteplan_coords` (JSON polygon) untuk render Leaflet site plan.
 *
 * Project juga dikasih kolom `siteplan_image_path` untuk simpan upload siteplan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->string('tahap', 16)->nullable()->after('project_id');
            $table->text('alamat')->nullable()->after('cluster');
            $table->decimal('price_list_minimum', 18, 2)->nullable()->after('harga_jual');
            $table->decimal('price_list_kavling', 18, 2)->nullable()->after('price_list_minimum');
            $table->string('zoning', 32)->nullable()->after('price_list_kavling');
            $table->decimal('panjang', 10, 2)->nullable()->after('zoning');
            $table->decimal('lebar', 10, 2)->nullable()->after('panjang');
            $table->decimal('tinggi', 10, 2)->nullable()->after('lebar');
            $table->decimal('luas_lebih_tanah', 10, 2)->nullable()->after('luas_tanah');
            $table->decimal('lebar_jalan', 10, 2)->nullable()->after('luas_bangunan');
            $table->string('arah_hadap', 32)->nullable()->after('lebar_jalan');
            $table->string('panorama', 32)->nullable()->after('arah_hadap');
            $table->string('tipe_properti', 32)->nullable()->after('panorama');   // RUMAH / RUKO / KAVLING
            $table->string('kategori_unit', 32)->nullable()->after('tipe_properti'); // KOMERSIL / SUBSIDI / DLL
            $table->json('siteplan_coords')->nullable()->after('kategori_unit');  // polygon vertices array
            $table->index(['tenant_id', 'project_id', 'tahap'], 'fo_units_tahap_idx');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('siteplan_image_path')->nullable()->after('address');
            $table->unsignedInteger('siteplan_width')->nullable()->after('siteplan_image_path');
            $table->unsignedInteger('siteplan_height')->nullable()->after('siteplan_width');
        });
    }

    public function down(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->dropIndex('fo_units_tahap_idx');
            $table->dropColumn([
                'tahap', 'alamat', 'price_list_minimum', 'price_list_kavling', 'zoning',
                'panjang', 'lebar', 'tinggi', 'luas_lebih_tanah', 'lebar_jalan',
                'arah_hadap', 'panorama', 'tipe_properti', 'kategori_unit', 'siteplan_coords',
            ]);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['siteplan_image_path', 'siteplan_width', 'siteplan_height']);
        });
    }
};
