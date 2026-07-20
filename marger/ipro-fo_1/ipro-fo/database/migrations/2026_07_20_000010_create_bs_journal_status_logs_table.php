<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log status jurnal (append-only) — riwayat setiap transisi: dibuat → finalisasi →
 * approve → batal approve → reject, dst. TIDAK pernah dihapus meskipun jurnal
 * di-approve/batal berkali-kali, sehingga jejaknya utuh dari awal hingga closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_journal_status_logs')) {
            return;
        }

        Schema::create('bs_journal_status_logs', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('journal_id');
            $t->string('action', 30);                 // created, finalized, approved, unapproved, rejected
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('note', 255)->nullable();
            $t->timestamp('created_at')->nullable();

            $t->index(['journal_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_journal_status_logs');
    }
};
