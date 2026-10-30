<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Listeners;

use App\Domain\Approval\Events\ApprovalApproved;
use App\Domain\Approval\Events\ApprovalRejected;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;

/**
 * TTD Dept Head atas Upload Berkas: disetujui → 'siap_invoice' (masuk antrean tim AP),
 * ditolak → 'ditolak'. Jenis dokumen lain diabaikan.
 */
final class SyncApBerkasStatus
{
    public function handle(ApprovalApproved|ApprovalRejected $event): void
    {
        $approval = $event->approval;
        if ($approval->document_type !== BsApBerkasUpload::DOCUMENT_TYPE) {
            return;
        }

        BsApBerkasUpload::query()
            ->whereKey($approval->approvable_id)
            ->where('status', BsApBerkasUpload::STATUS_MENUNGGU_TTD)
            ->update(['status' => $event instanceof ApprovalApproved
                ? BsApBerkasUpload::STATUS_SIAP_INVOICE
                : BsApBerkasUpload::STATUS_DITOLAK]);
    }
}
