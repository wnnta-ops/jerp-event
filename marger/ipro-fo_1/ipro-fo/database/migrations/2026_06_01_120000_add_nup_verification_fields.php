<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap VERIFIKASI NUP — meniru "Verifikasi Data Customer" eProperty.
 *
 * Pendaftaran NUP baru masuk antrian verifikasi (verification_status = 'pending')
 * dulu. Supervisor melakukan Approve / Pending / Reject. Hanya yang 'approved'
 * yang masuk ke Daftar NUP utama.
 *
 * Data lama di-backfill ke 'approved' supaya daftar yang sudah ada tidak hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nup_registrations', function (Blueprint $table): void {
            $table->string('verification_status', 20)->default('pending'); // pending/approved/rejected
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->string('verification_note', 500)->nullable();
            $table->index('verification_status');
        });

        // Backfill: semua NUP yang sudah ada dianggap sudah terverifikasi.
        DB::table('nup_registrations')->update(['verification_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('nup_registrations', function (Blueprint $table): void {
            $table->dropIndex(['verification_status']);
            $table->dropColumn(['verification_status', 'verified_at', 'verified_by', 'verification_note']);
        });
    }
};
