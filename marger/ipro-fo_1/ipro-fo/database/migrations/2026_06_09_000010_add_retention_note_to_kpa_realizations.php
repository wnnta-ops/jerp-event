<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #91/#100 — Keterangan free-text untuk kolom Retensi / Pengurangan DAJAM pada realisasi pencairan KPA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpa_realizations', function (Blueprint $t): void {
            if (! Schema::hasColumn('kpa_realizations', 'retention_note')) {
                $t->string('retention_note', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpa_realizations', function (Blueprint $t): void {
            if (Schema::hasColumn('kpa_realizations', 'retention_note')) {
                $t->dropColumn('retention_note');
            }
        });
    }
};
