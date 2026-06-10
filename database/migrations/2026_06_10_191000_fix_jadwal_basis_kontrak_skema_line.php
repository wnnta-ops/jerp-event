<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * jadwal_basis selaras master skema yang memakai 'HARI'/'BULAN' (bukan 'M'/'D').
 * Ubah enum → varchar agar fleksibel & cocok saat "Salin dari Skema Master".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE fo_kontrak_skema_line MODIFY jadwal_basis VARCHAR(12) NOT NULL DEFAULT 'HARI'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE fo_kontrak_skema_line MODIFY jadwal_basis ENUM('M','D') NOT NULL DEFAULT 'D'");
    }
};
