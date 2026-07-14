<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TTS dihilangkan sebagai TAHAP inisiasi KPR (kini 7 tahap; TTS = sumber data saja, proses mulai dari SPRS).
 * Geser current_stage record lama (penomoran 8-tahap) turun 1 agar selaras penomoran baru (minimal 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kpr_initiations')) {
            return;
        }
        DB::table('kpr_initiations')->update([
            'current_stage' => DB::raw('GREATEST(current_stage - 1, 1)'),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('kpr_initiations')) {
            return;
        }
        // Best-effort reverse (tahap TTS lama tak bisa dipulihkan persis) — geser naik 1 (maks 8).
        DB::table('kpr_initiations')->update([
            'current_stage' => DB::raw('LEAST(current_stage + 1, 8)'),
        ]);
    }
};
