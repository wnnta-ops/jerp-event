<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event MANDIRI: event tak wajib terhubung ke event NUP FO.
 *
 * - et_events.fo_nup_event_id boleh kosong (= mandiri).
 * - Peserta event mandiri dikenali per CUSTOMER (fo_customer_id), bukan per NUP;
 *   event terhubung FO tetap per NUP (fo_nup_registration_id).
 * - et_scan_logs.participant_id: duplikat & "batalkan scan" dikunci per peserta,
 *   karena event mandiri tak punya fo_nup_registration_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('et_events', function (Blueprint $table) {
            $table->unsignedBigInteger('fo_nup_event_id')->nullable()->change();
        });

        Schema::table('et_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('fo_nup_registration_id')->nullable()->change();
            $table->unsignedBigInteger('fo_customer_id')->nullable()->after('fo_nup_registration_id')->index();
            $table->unique(['event_id', 'fo_customer_id']);
        });

        Schema::table('et_scan_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('participant_id')->nullable()->after('scan_point_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('et_scan_logs', function (Blueprint $table) {
            $table->dropIndex(['participant_id']);
            $table->dropColumn('participant_id');
        });

        Schema::table('et_participants', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'fo_customer_id']);
            $table->dropIndex(['fo_customer_id']);
            $table->dropColumn('fo_customer_id');
        });
        // fo_nup_event_id / fo_nup_registration_id sengaja tidak dikembalikan NOT NULL:
        // baris event mandiri akan membuatnya gagal.
    }
};
