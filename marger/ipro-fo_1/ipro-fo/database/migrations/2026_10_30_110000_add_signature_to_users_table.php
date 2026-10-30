<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanda tangan (TTD) per user — file PNG di disk privat ('local'), diunggah lewat
 * Security → Username (User) → Edit. Distempel pada tahap approval yang disetujui
 * user tsb (Approval Inbox) dan dipakai printout dokumen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            if (! Schema::hasColumn('users', 'signature_path')) {
                $t->string('signature_path')->nullable()->after('avatar_path');
                $t->timestamp('signature_updated_at')->nullable()->after('signature_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            if (Schema::hasColumn('users', 'signature_path')) {
                $t->dropColumn(['signature_path', 'signature_updated_at']);
            }
        });
    }
};
