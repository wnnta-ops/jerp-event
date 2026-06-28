<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel wilayah Indonesia bertingkat (Provinsi → Kab/Kota → Kecamatan → Kelurahan).
 * Sumber: kode wilayah Kemendagri (format kode bertitik: 11 / 11.01 / 11.01.01 / 11.01.01.2001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('parent_code', 16)->nullable();
            $table->unsignedTinyInteger('level'); // 1=Provinsi 2=Kab/Kota 3=Kecamatan 4=Kelurahan/Desa
            $table->string('name', 150);

            $table->index(['parent_code', 'name']);
            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
