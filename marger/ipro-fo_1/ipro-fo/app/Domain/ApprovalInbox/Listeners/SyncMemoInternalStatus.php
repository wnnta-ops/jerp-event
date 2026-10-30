<?php

declare(strict_types=1);

namespace App\Domain\ApprovalInbox\Listeners;

use App\Domain\Approval\Events\ApprovalApproved;
use App\Domain\Approval\Events\ApprovalRejected;
use App\Domain\ApprovalInbox\Models\MemoInternal;

/**
 * Status Memo Internal mengikuti approval-nya: disetujui penuh → 'disetujui',
 * ditolak di tahap mana pun → 'ditolak'. Jenis dokumen lain diabaikan.
 */
final class SyncMemoInternalStatus
{
    public function handle(ApprovalApproved|ApprovalRejected $event): void
    {
        $approval = $event->approval;
        if ($approval->document_type !== MemoInternal::DOCUMENT_TYPE) {
            return;
        }

        MemoInternal::query()
            ->whereKey($approval->approvable_id)
            ->update(['status' => $event instanceof ApprovalApproved ? 'disetujui' : 'ditolak']);
    }
}
