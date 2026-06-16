<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tambah tipe 'GMC' ke fo_kontrak_tagihan.tipe — Gimmick (free untuk customer,
 * tidak ditagih/tidak masuk SPR; harga untuk pembayaran subsidi developer di BO).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE fo_kontrak_tagihan MODIFY tipe ENUM('BF','DP','ANG','ADM','GMC','LAIN') NOT NULL DEFAULT 'ANG'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE fo_kontrak_tagihan MODIFY tipe ENUM('BF','DP','ANG','ADM','LAIN') NOT NULL DEFAULT 'ANG'");
    }
};
