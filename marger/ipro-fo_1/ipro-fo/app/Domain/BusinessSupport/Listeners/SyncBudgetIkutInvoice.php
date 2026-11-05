<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Listeners;

use App\Domain\Approval\Events\ApprovalApproved;
use App\Domain\Approval\Events\ApprovalRejected;
use App\Domain\BusinessSupport\Models\BsApBudgetRequest;
use App\Domain\BusinessSupport\Services\BudgetIkutInvoice;
use App\Domain\BusinessSupport\Services\PengajuanBudgetInvoice;

/**
 * Invoice Hutang / Pengajuan Budget Invoice diputuskan penuh → alokasi & koreksi turunannya ikut:
 * disetujui → anggaran dipindah / dikoreksi (sisa budget asal dicek lagi — kurang → keputusan
 * approval batal), ditolak → ditolak. Pengajuan Budget Invoice lalu disinkronkan statusnya.
 */
final class SyncBudgetIkutInvoice
{
    public function __construct(
        private readonly BudgetIkutInvoice $ikut,
        private readonly PengajuanBudgetInvoice $pengajuan,
    ) {}

    public function handle(ApprovalApproved|ApprovalRejected $event): void
    {
        $approval = $event->approval;
        if (! BudgetIkutInvoice::berlaku($approval)) {
            return;
        }
        $this->ikut->putuskan($approval, $event instanceof ApprovalApproved, (int) auth()->id());

        if ($approval->document_type === BsApBudgetRequest::DOCUMENT_TYPE
            && ($req = BsApBudgetRequest::query()->find($approval->approvable_id))) {
            $this->pengajuan->sinkron($req);
        }
    }
}
