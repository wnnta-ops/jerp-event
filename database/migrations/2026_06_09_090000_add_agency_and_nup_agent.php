<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales → SPR flow:
 *  - fo_agents.agency_name : "Agensi Properti" (mis. Uhome / DB Real Estate).
 *    Tampil di SPRS & SPR sbg "Nama Sales - Agensi Properti". Terkait Komisi/BLT.
 *  - fo_nup_registrations.agent_id : Sales/Agent yang mendaftarkan NUP (dipilih
 *    dari master /fo/agents). Dibawa terus sampai Kontrak (closing) → SPR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_agents', function (Blueprint $table) {
            $table->string('agency_name', 191)->nullable()->after('name');
        });

        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('project_pref_id')
                ->constrained('fo_agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fo_nup_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
        });

        Schema::table('fo_agents', function (Blueprint $table) {
            $table->dropColumn('agency_name');
        });
    }
};
