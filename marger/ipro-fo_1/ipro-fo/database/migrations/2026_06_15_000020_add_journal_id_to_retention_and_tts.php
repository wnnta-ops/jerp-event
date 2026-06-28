<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Approval Retensi & Approval UTJ/DP: tautan ke jurnal GL agar tidak dijurnal dua kali.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_retentions', function (Blueprint $table) {
            if (! Schema::hasColumn('kpr_retentions', 'journal_id')) {
                $table->unsignedBigInteger('journal_id')->nullable();
                $table->index('journal_id');
            }
        });
        Schema::table('tts_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('tts_receipts', 'journal_id')) {
                $table->unsignedBigInteger('journal_id')->nullable();
                $table->index('journal_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpr_retentions', function (Blueprint $table) {
            $table->dropColumn('journal_id');
        });
        Schema::table('tts_receipts', function (Blueprint $table) {
            $table->dropColumn('journal_id');
        });
    }
};
