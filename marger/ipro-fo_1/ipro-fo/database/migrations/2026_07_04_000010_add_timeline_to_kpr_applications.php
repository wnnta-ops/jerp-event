<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cap waktu tiap tahap Proses KPR (untuk Laporan Durasi Proses, seperti pada Inisiasi KPR).
 * Format: { draft: iso, submitted: iso, sp3k: iso, si: iso, akad: iso, disbursed: iso }
 * — key = tahap yang SELESAI (saat maju ke tahap berikutnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpr_applications', function (Blueprint $t): void {
            $t->json('timeline')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('kpr_applications', function (Blueprint $t): void {
            $t->dropColumn('timeline');
        });
    }
};
