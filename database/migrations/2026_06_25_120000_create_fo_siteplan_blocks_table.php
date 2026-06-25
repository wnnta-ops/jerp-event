<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blok area siteplan — penanda area yang BELUM dibuka (mis. "TAHAP 2"): kotak/
 * polygon hitam + teks, TIDAK terikat unit. Digambar di Polygon Editor, ditampilkan
 * menutup area di /siteplan, /siteplanlive, dan Pilih Unit. Koordinat dlm ruang
 * IMAGE (sama dgn fo_units.siteplan_coords).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_siteplan_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('lokasi_id')->nullable()->constrained('fo_lokasi')->nullOnDelete();
            $table->json('coords');                       // [[x,y], ...] ruang image
            $table->string('label')->nullable();          // teks, mis. "TAHAP 2"
            $table->string('color', 16)->default('#0a0a0a');
            $table->decimal('opacity', 4, 2)->default(0.85);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'lokasi_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_siteplan_blocks');
    }
};
