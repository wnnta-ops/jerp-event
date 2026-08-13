<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Setup Preferensi Unit → master pilihan "Preferensi Unit" di form NUP
        // publik (sebelumnya hardcoded di komponen). Kini bisa ditambah/diubah/
        // dihapus sendiri lewat panel, dan pendaftar memilih SATU saja.
        Schema::create('fo_preferensi_unit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1)->index();
            $table->string('nama', 100);
            $table->unsignedInteger('urutan')->default(0);   // angka kecil tampil lebih dulu
            $table->boolean('is_active')->default(true);
            $table->string('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'nama']);
        });

        // Seed opsi bawaan — sama persis dengan yang selama ini hardcoded, supaya
        // form tetap menampilkan pilihan yang sama setelah migrasi.
        $now = now();
        $defaults = [
            'Rumah 200 Jutaan',
            'Rumah 400 Jutaan',
            'Rumah 600 Jutaan',
            'Rumah 800 — 999 Jutaan',
            'RUKO',
        ];
        $rows = [];
        foreach ($defaults as $i => $nama) {
            $rows[] = [
                'tenant_id' => 1,
                'nama' => $nama,
                'urutan' => ($i + 1) * 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('fo_preferensi_unit')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_preferensi_unit');
    }
};
