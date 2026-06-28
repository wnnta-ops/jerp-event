<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Keputusan SP3K per aplikasi KPR: 'lanjut' (boleh ke akad) / 'batal'. Null = belum diputuskan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('kpr_applications', 'decision')) {
                $table->string('decision', 20)->nullable();
            }
            if (! Schema::hasColumn('kpr_applications', 'decided_at')) {
                $table->date('decided_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpr_applications', function (Blueprint $table) {
            $table->dropColumn(['decision', 'decided_at']);
        });
    }
};
