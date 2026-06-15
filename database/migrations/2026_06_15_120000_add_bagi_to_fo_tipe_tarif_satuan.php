<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tambah satuan 'BAGI' (÷) ke fo_tipe_tarif.satuan — pembagi DPP (DPP = harga ÷ nilai). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE fo_tipe_tarif MODIFY satuan ENUM('RP','PERSEN','BAGI') NOT NULL DEFAULT 'PERSEN'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE fo_tipe_tarif MODIFY satuan ENUM('RP','PERSEN') NOT NULL DEFAULT 'PERSEN'");
    }
};
