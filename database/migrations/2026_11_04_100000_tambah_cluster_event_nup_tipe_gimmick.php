<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cluster (fo_lokasi) pada Event NUP & Tipe Gimmick. Semua cluster berada di satu
 * project, jadi project_id saja tak bisa membedakan event/paket milik cluster mana.
 *  - Event NUP: Pilih Unit menolak scan NUP dari event cluster lain.
 *  - Tipe Gimmick: Closing/SPR hanya menawarkan paket milik cluster unit.
 * Kosong = tidak dibatasi (data lama tetap jalan).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['fo_nup_events', 'fo_tipe_gimmick'] as $tabel) {
            if (Schema::hasColumn($tabel, 'lokasi_id')) {
                continue;
            }
            Schema::table($tabel, function (Blueprint $table) {
                $table->foreignId('lokasi_id')->nullable()->after('project_id')
                    ->constrained('fo_lokasi')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['fo_nup_events', 'fo_tipe_gimmick'] as $tabel) {
            if (Schema::hasColumn($tabel, 'lokasi_id')) {
                Schema::table($tabel, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('lokasi_id');
                });
            }
        }
    }
};
