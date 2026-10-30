<?php

declare(strict_types=1);

namespace App\Domain\ApprovalInbox\Listeners;

use App\Domain\Approval\Events\ApprovalApproved;
use App\Domain\Approval\Events\ApprovalRejected;
use App\Domain\Approval\Events\ApprovalStepApproved;
use App\Domain\Approval\Events\ApprovalSubmitted;
use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Domain\ApprovalInbox\Services\InboxNotifier;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Models\User;
use Throwable;

/**
 * Lonceng notifikasi untuk dokumen Approval Inbox (Memo, TTD Berkas, Invoice dari berkas):
 *   diajukan / satu level disetujui → approver berikutnya
 *   disetujui penuh                 → pengaju (+ tim AP untuk berkas yang siap diinvoice)
 *   ditolak                         → pengaju (+ pengunggah berkas untuk invoice)
 * Gagal kirim notifikasi tidak boleh menggagalkan approval — dilaporkan saja.
 */
final class NotifyApprovalProgress
{
    public function __construct(private readonly InboxNotifier $notifier) {}

    public function handle(ApprovalSubmitted|ApprovalStepApproved|ApprovalApproved|ApprovalRejected $event): void
    {
        $a = $event->approval;
        if (! in_array($a->document_type, ApprovalInboxQuery::DOCUMENT_TYPES, true)) {
            return;
        }

        try {
            $doc = $this->label($a);
            $url = route('approval-inbox.show', $a->id);
            $submitter = User::query()->find($a->submitted_by);

            match (true) {
                $event instanceof ApprovalSubmitted, $event instanceof ApprovalStepApproved => $this->notifier->notify(
                    $this->notifier->nextApprovers($a), 'approval.menunggu',
                    'Menunggu persetujuan Anda', "{$doc} perlu persetujuan Anda.", $url,
                ),
                $event instanceof ApprovalApproved => $this->disetujui($a, $doc, $url, $submitter),
                default => $this->ditolak($a, $doc, $url, $submitter),
            };
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function disetujui(Approval $a, string $doc, string $url, ?User $submitter): void
    {
        $this->notifier->notify([$submitter], 'approval.disetujui', 'Disetujui', "{$doc} sudah disetujui penuh.", $url);

        if ($a->document_type === BsApBerkasUpload::DOCUMENT_TYPE) {
            $this->notifier->notify(
                $this->notifier->usersWithPermission('ap.invoice.create'), 'berkas.siap_invoice',
                'Berkas siap dibuat invoice', "{$doc} sudah ditandatangani Dept Head.",
                route('bs.ap.berkas.show', $a->approvable_id),
            );
        }
    }

    private function ditolak(Approval $a, string $doc, string $url, ?User $submitter): void
    {
        $alasan = $a->steps()->where('status', 'rejected')->value('notes');
        $body = "{$doc} ditolak".($alasan ? ": {$alasan}" : '.');
        $this->notifier->notify([$submitter], 'approval.ditolak', 'Ditolak', $body, $url);

        if ($a->document_type === 'ap_invoice') {
            $berkas = BsApBerkasUpload::query()->with('uploader')
                ->whereHas('invoices', fn ($q) => $q->whereKey($a->approvable_id))->first();
            $this->notifier->notify([$berkas?->uploader], 'approval.ditolak', 'Invoice dari berkas Anda ditolak', $body, $url);
        }
    }

    private function label(Approval $a): string
    {
        $m = $a->loadMissing('approvable')->approvable;
        $type = ApprovalInboxQuery::TYPE_LABELS[$a->document_type] ?? $a->document_type;
        $no = $m->memo_number ?? $m->berkas_number ?? $m->invoice_number ?? ('#'.$a->id);

        return "{$type} {$no}";
    }
}
