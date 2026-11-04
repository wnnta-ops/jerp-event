<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Listeners;

use App\Domain\Approval\Events\ApprovalApproved;
use App\Domain\Approval\Events\ApprovalRejected;
use App\Domain\BusinessSupport\Models\BsApBudgetRequest;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Services\BudgetAllocationService;
use App\Domain\BusinessSupport\Services\PengajuanBudgetInvoice;

/**
 * Alokasi budget di Approval Inbox: disetujui → anggaran dipindah (sisa budget asal dicek lagi),
 * ditolak → 'rejected'. Lalu, untuk alokasi & koreksi yang lahir dari Pengajuan Budget Invoice,
 * status pengajuannya disinkronkan (didaftarkan SESUDAH SyncBudgetCorrectionStatus).
 */
final class SyncPengajuanBudgetInvoice
{
    public function __construct(
        private readonly BudgetAllocationService $alokasi,
        private readonly PengajuanBudgetInvoice $pengajuan,
    ) {}

    public function handle(ApprovalApproved|ApprovalRejected $event): void
    {
        $approval = $event->approval;
        $m = match ($approval->document_type) {
            BsBudgetAllocation::DOCUMENT_TYPE => BsBudgetAllocation::query()->find($approval->approvable_id),
            BsBudgetCorrection::DOCUMENT_TYPE => BsBudgetCorrection::query()->find($approval->approvable_id),
            default => null,
        };
        if (! $m) {
            return;
        }
        if ($m instanceof BsBudgetAllocation) {
            $this->alokasi->putuskan($m, $event instanceof ApprovalApproved, (int) auth()->id());
        }
        if ($m->source_type === BsApBudgetRequest::SOURCE && ($req = BsApBudgetRequest::query()->find($m->source_id))) {
            $this->pengajuan->sinkron($req);
        }
    }
}
