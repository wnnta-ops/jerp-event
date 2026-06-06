<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            // No. Unit kanonik: {Lokasi(kode project)}-{Tahap}-{Blok}-{Nomer}.
            // Dibangun otomatis dari komponennya (lihat Unit::composeKodeUnit) dan
            // dipakai sebagai identitas unit di seluruh modul (koordinat, penjualan).
            $table->string('kode_unit', 64)->nullable()->after('no_unit');
            $table->index(['tenant_id', 'kode_unit']);
        });

        // Backfill unit yang sudah ada (DB-agnostic — jalan di MySQL & SQLite).
        $codes = [];
        DB::table('fo_units')->whereNotNull('no_unit')->orderBy('id')
            ->chunkById(500, function ($rows) use (&$codes) {
                foreach ($rows as $r) {
                    if (! array_key_exists($r->project_id, $codes)) {
                        $codes[$r->project_id] = DB::table('projects')->where('id', $r->project_id)->value('code');
                    }
                    $parts = array_filter(
                        [$codes[$r->project_id], $r->tahap, $r->blok, $r->no_unit],
                        fn ($v) => $v !== null && trim((string) $v) !== '',
                    );
                    DB::table('fo_units')->where('id', $r->id)->update([
                        'kode_unit' => implode('-', array_map(fn ($v) => trim((string) $v), $parts)),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'kode_unit']);
            $table->dropColumn('kode_unit');
        });
    }
};
