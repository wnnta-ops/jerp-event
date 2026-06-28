<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #114 — SIP bisa memuat lebih dari satu bank sumber: bank sumber dipindah ke level baris
 * (bs_sip_lines.cash_account_id). Header bs_sips.cash_account_id dipertahankan sebagai bank
 * sumber utama (blok pertama) untuk kompatibilitas tampilan lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_sip_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('bs_sip_lines', 'cash_account_id')) {
                $table->unsignedBigInteger('cash_account_id')->nullable();
            }
        });

        // Backfill bank sumber per baris dari header SIP (data lama single-bank).
        DB::statement('UPDATE bs_sip_lines SET cash_account_id = (SELECT cash_account_id FROM bs_sips WHERE bs_sips.id = bs_sip_lines.sip_id) WHERE cash_account_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('bs_sip_lines', function (Blueprint $table) {
            if (Schema::hasColumn('bs_sip_lines', 'cash_account_id')) {
                $table->dropColumn('cash_account_id');
            }
        });
    }
};
