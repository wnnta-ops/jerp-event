<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pos opsional: boleh dilewati peserta. Melompati pos opsional tidak dianggap
 * "urutan tidak sesuai", dan antrean pos berikutnya ikut menampilkan peserta
 * yang sedang menunggu di pos opsional sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('et_stations', function (Blueprint $table) {
            $table->boolean('is_optional')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('et_stations', function (Blueprint $table) {
            $table->dropColumn('is_optional');
        });
    }
};
