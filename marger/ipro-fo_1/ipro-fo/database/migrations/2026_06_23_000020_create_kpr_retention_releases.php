<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat pelepasan retensi — satu baris per event Release (parsial/penuh) agar
 * histori tiap pencairan retensi tampil di detail KPR.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kpr_retention_releases')) {
            Schema::create('kpr_retention_releases', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('kpr_retention_id')->constrained()->cascadeOnDelete();
                $t->decimal('amount', 20, 2);
                $t->date('released_at');
                $t->text('reason')->nullable();
                $t->unsignedBigInteger('tts_id')->nullable();
                $t->timestamps();
            });

            // Backfill: retensi yg sudah punya released_amount > 0 → 1 baris histori (data lama).
            $now = now();
            foreach (DB::table('kpr_retentions')->where('released_amount', '>', 0)->get() as $r) {
                DB::table('kpr_retention_releases')->insert([
                    'kpr_retention_id' => $r->id,
                    'amount'           => $r->released_amount,
                    'released_at'      => $r->released_at ?: $now->toDateString(),
                    'reason'           => $r->claim_reason,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpr_retention_releases');
    }
};
