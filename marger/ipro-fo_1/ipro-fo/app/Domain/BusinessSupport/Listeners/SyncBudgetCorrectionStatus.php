<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Listeners;

use App\Domain\Approval\Events\ApprovalApproved;
use App\Domain\Approval\Events\ApprovalRejected;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Services\BudgetCorrectionService;

/**
 * Koreksi budget di Approval Inbox: disetujui penuh → diterapkan ke master budget, ditolak →
 * 'rejected'. Jenis dokumen lain diabaikan.
 */
final class SyncBudgetCorrectionStatus
{
    public function __construct(private readonly BudgetCorrectionService $service) {}

    public function handle(ApprovalApproved|ApprovalRejected $event): void
    {
        $approval = $event->approval;
        if ($approval->document_type !== BsBudgetCorrection::DOCUMENT_TYPE) {
            return;
        }
        $c = BsBudgetCorrection::query()->find($approval->approvable_id);
        if ($c) {
            $this->service->putuskan($c, $event instanceof ApprovalApproved, auth()->id());
        }
    }
}
