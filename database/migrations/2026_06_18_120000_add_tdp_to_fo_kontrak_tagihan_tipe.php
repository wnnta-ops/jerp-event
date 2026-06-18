<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tambah tipe 'TDP' (Tambah DP) ke fo_kontrak_tagihan.tipe.
 * TDP = DP tambahan dari customer (ditagih/AR) yang MENGURANGI Pencairan KPR.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE fo_kontrak_tagihan MODIFY COLUMN tipe ENUM('BF','DP','ANG','ADM','GMC','LAIN','TDP') NOT NULL DEFAULT 'ANG'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE fo_kontrak_tagihan MODIFY COLUMN tipe ENUM('BF','DP','ANG','ADM','GMC','LAIN') NOT NULL DEFAULT 'ANG'");
    }
};
