<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flow Komisi mengikuti BLT: Pengajuan (DRAFT→PENDING, batch No. Pengajuan) → Approval
 * (jenis KOMISI di modul Approval) → Realisasi (PAID, per batch). Tambah kolom batch,
 * status PENDING, dan jenis approval KOMISI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_komisi', function (Blueprint $table) {
            $table->string('no_pengajuan')->nullable()->after('no_komisi')->index();
        });
        DB::statement("ALTER TABLE fo_komisi MODIFY status ENUM('DRAFT','PENDING','APPROVED','PAID','BATAL') NOT NULL DEFAULT 'DRAFT'");
        DB::statement("ALTER TABLE fo_approvals MODIFY jenis ENUM('DISKON','BATAL_KONTRAK','ADJ_HARGA','RESCHEDULE','GANTI_NAMA','GANTI_UNIT','REFUND','BLT','KOMISI','LAIN') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE fo_approvals MODIFY jenis ENUM('DISKON','BATAL_KONTRAK','ADJ_HARGA','RESCHEDULE','GANTI_NAMA','GANTI_UNIT','REFUND','BLT','LAIN') NOT NULL");
        DB::statement("ALTER TABLE fo_komisi MODIFY status ENUM('DRAFT','APPROVED','PAID','BATAL') NOT NULL DEFAULT 'DRAFT'");
        Schema::table('fo_komisi', function (Blueprint $table) {
            $table->dropIndex(['no_pengajuan']);
            $table->dropColumn('no_pengajuan');
        });
    }
};
