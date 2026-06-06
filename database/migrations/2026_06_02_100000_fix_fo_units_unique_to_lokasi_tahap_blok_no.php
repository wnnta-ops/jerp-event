<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas unit mengikuti format kode No. Unit: Lokasi-Tahap-Blok-Nomer
 * (mis. JRK3-004-XXI-3A). Unique key lama hanya (tenant_id, project_id, blok,
 * no_unit) — TANPA tahap — sehingga dua unit dengan blok+nomer sama di tahap
 * berbeda saling tabrak. Diganti ke (tenant_id, lokasi_id, tahap_id, blok,
 * no_unit) agar sesuai domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->dropUnique('fo_units_blok_no_unique');
            $table->unique(
                ['tenant_id', 'lokasi_id', 'tahap_id', 'blok', 'no_unit'],
                'fo_units_lokasi_tahap_blok_no_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('fo_units', function (Blueprint $table) {
            $table->dropUnique('fo_units_lokasi_tahap_blok_no_unique');
            $table->unique(
                ['tenant_id', 'project_id', 'blok', 'no_unit'],
                'fo_units_blok_no_unique',
            );
        });
    }
};
