<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memo Internal (MI) — dokumen yang diajukan staf/Dept Head lalu berjalan di rantai
 * approval bertingkat (approval_chains document_type 'memo_internal'):
 * Accounting → Finance → GM → Direktur → MW/BA. Dipantau lewat menu Approval Inbox.
 *
 * Status memo mengikuti approval-nya: diajukan → disetujui / ditolak (lihat
 * SyncMemoInternalStatus). approval_id menunjuk baris approvals yang aktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('memo_internals')) {
            return;
        }

        Schema::create('memo_internals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->index();
            $t->string('memo_number', 40)->unique();
            $t->date('memo_date');
            $t->string('department', 100);
            $t->string('subject', 200);          // perihal
            $t->text('body');                    // isi memo
            $t->decimal('amount', 18, 2)->nullable(); // nominal (opsional — memo non-dana)
            $t->string('status', 20)->default('diajukan')->index();
            $t->foreignId('approval_id')->nullable()->index();
            $t->foreignId('created_by')->index();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memo_internals');
    }
};
