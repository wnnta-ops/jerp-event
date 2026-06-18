<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tambah jenis approval DISKON_SPRS (diskon saat masih SPRS — single-level/langsung GM).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE fo_approvals MODIFY jenis ENUM('DISKON','DISKON_SPRS','BATAL_KONTRAK','ADJ_HARGA','RESCHEDULE','GANTI_NAMA','GANTI_UNIT','REFUND','BLT','KOMISI','LAIN') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE fo_approvals MODIFY jenis ENUM('DISKON','BATAL_KONTRAK','ADJ_HARGA','RESCHEDULE','GANTI_NAMA','GANTI_UNIT','REFUND','BLT','KOMISI','LAIN') NOT NULL");
    }
};
