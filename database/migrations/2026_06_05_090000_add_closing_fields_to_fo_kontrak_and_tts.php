<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing NUP membutuhkan:
 * - fo_kontrak.skema_id  : skema cara bayar yang dipilih saat closing (mengikat harga).
 * - fo_kontrak.agent_id  : sales person (Kode Sales) pada kontrak.
 * - fo_tts.skip_gl_posting: TTS dari UTJ yang SUDAH diposting ke jurnal saat bayar Xendit;
 *   saat "Buka Kuitansi" (TTS→BKM) jangan posting ulang agar tidak dobel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $table) {
            if (! Schema::hasColumn('fo_kontrak', 'skema_id')) {
                $table->foreignId('skema_id')->nullable()->after('unit_id')->constrained('fo_skema')->nullOnDelete();
            }
            if (! Schema::hasColumn('fo_kontrak', 'agent_id')) {
                $table->foreignId('agent_id')->nullable()->after('skema_id')->constrained('fo_agents')->nullOnDelete();
            }
        });

        Schema::table('fo_tts', function (Blueprint $table) {
            if (! Schema::hasColumn('fo_tts', 'skip_gl_posting')) {
                $table->boolean('skip_gl_posting')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fo_kontrak', function (Blueprint $table) {
            if (Schema::hasColumn('fo_kontrak', 'agent_id')) {
                $table->dropConstrainedForeignId('agent_id');
            }
            if (Schema::hasColumn('fo_kontrak', 'skema_id')) {
                $table->dropConstrainedForeignId('skema_id');
            }
        });
        Schema::table('fo_tts', function (Blueprint $table) {
            if (Schema::hasColumn('fo_tts', 'skip_gl_posting')) {
                $table->dropColumn('skip_gl_posting');
            }
        });
    }
};
