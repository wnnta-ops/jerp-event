<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tambah jenis 'BLT' (Pengajuan BLT) ke enum fo_approvals.jenis. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE fo_approvals MODIFY jenis ENUM('DISKON','BATAL_KONTRAK','ADJ_HARGA','RESCHEDULE','GANTI_NAMA','GANTI_UNIT','REFUND','BLT','LAIN') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE fo_approvals MODIFY jenis ENUM('DISKON','BATAL_KONTRAK','ADJ_HARGA','RESCHEDULE','GANTI_NAMA','GANTI_UNIT','REFUND','LAIN') NOT NULL");
    }
};
