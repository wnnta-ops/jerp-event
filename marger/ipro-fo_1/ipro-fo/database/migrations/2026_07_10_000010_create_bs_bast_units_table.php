<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generate BAST — baris unit hasil import Excel (Contractor Management).
 * Menyimpan kolom sesuai lampiran daftar unit; ditampilkan berdampingan dengan
 * unit master (units) di modul Generate BAST. Baris di sini adalah unit yang
 * BELUM ada di master (supplemen), di-upsert per (tenant_id, unit_no).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_bast_units', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('project', 100)->nullable();     // Proyek
            $t->string('unit_no', 60);                   // No. Unit (kode unit)
            $t->string('location', 150)->nullable();     // Lokasi
            $t->string('phase', 60)->nullable();         // Tahap
            $t->string('block', 60)->nullable();         // Blok
            $t->string('number', 60)->nullable();        // Nomer
            $t->string('type', 60)->nullable();          // Tipe
            $t->string('cluster', 100)->nullable();      // Cluster
            $t->unsignedInteger('lt')->nullable();       // Luas Tanah
            $t->unsignedInteger('lb')->nullable();       // Luas Bangunan
            $t->decimal('price', 20, 2)->default(0);     // Harga jual
            $t->string('unit_status', 40)->nullable();   // Status
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['tenant_id', 'unit_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_bast_units');
    }
};
