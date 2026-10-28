<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval perubahan data Proses KPR (Set Up → Setup Approval KPR, menu KPR → Approval).
 *
 *  kpr_approval_settings — per tahap: wajib approval?, siapa approver-nya, siapa yang boleh
 *                          mengubah langsung. Baris belum ada = tahap itu tidak wajib approval,
 *                          sehingga setelah migrasi proses KPR berjalan persis seperti sebelumnya.
 *  kpr_change_requests   — setiap perubahan (diajukan/disetujui/ditolak/langsung), lengkap dengan
 *                          data lama & baru — sekaligus riwayat perubahan di halaman KPR.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kpr_approval_settings')) {
            Schema::create('kpr_approval_settings', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->nullable()->index();
                $t->string('stage', 30);
                $t->boolean('is_required')->default(false);
                $t->json('approver_user_ids')->nullable();
                $t->json('bypass_user_ids')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'stage']);
            });
        }

        if (! Schema::hasTable('kpr_change_requests')) {
            Schema::create('kpr_change_requests', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->nullable()->index();
                $t->foreignId('kpr_application_id')->index();
                $t->string('stage', 30)->index();
                $t->string('action', 30);
                $t->unsignedBigInteger('target_id')->nullable(); // mis. id realisasi pencairan
                $t->json('old_data')->nullable();
                $t->json('new_data')->nullable();
                $t->text('reason')->nullable();
                // pending | approved | rejected | cancelled | direct (diubah langsung tanpa approval)
                $t->string('status', 20)->default('pending')->index();
                $t->foreignId('requested_by')->nullable();
                $t->foreignId('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->text('decision_note')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpr_change_requests');
        Schema::dropIfExists('kpr_approval_settings');
    }
};
