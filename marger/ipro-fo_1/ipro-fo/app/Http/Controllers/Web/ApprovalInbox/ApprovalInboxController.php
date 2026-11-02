<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\ApprovalInbox;

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Approval Inbox — tracking & keputusan approval bertingkat. Pengajuannya datang dari AP
 * (TTD Berkas, Invoice Hutang); form Ajukan Memo sudah dihapus, memo lama tetap bisa dibuka.
 * Visibilitas per baris diatur ApprovalInboxQuery (staf hanya melihat ajuannya sendiri).
 */
class ApprovalInboxController extends Controller
{
    public function __construct(private readonly ApprovalInboxQuery $inbox) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('approvalinbox.view'), 403);

        $lists = [
            'perlu' => $this->inbox->needsMe($user),
            'pengajuan' => $this->inbox->mine($user),
            'riwayat' => $this->inbox->history($user),
        ];
        $tab = $request->input('tab');
        if (! array_key_exists((string) $tab, $lists)) {
            $tab = $lists['perlu']->isNotEmpty() ? 'perlu' : 'pengajuan';
        }

        return Inertia::render('ApprovalInbox/Index', [
            'tab' => $tab,
            'counts' => array_map(fn ($l) => $l->count(), $lists),
            'rows' => $lists[$tab]->map(fn (Approval $a) => $this->inbox->row($a))->values(),
        ]);
    }

    public function show(Request $request, Approval $approval): Response
    {
        $user = $request->user();
        abort_unless($user?->can('approvalinbox.view'), 403);
        abort_unless(in_array($approval->document_type, ApprovalInboxQuery::DOCUMENT_TYPES, true), 404);
        $approval->load('steps')->loadMorph('approvable', [
            \App\Domain\BusinessSupport\Models\BsApBerkasUpload::class => ['supplier:id,name', 'files.jenis:id,name'],
            \App\Domain\BusinessSupport\Models\BsApInvoice::class => ['supplier:id,name'],
        ]);
        abort_unless($this->inbox->canView($user, $approval), 403);

        return Inertia::render('ApprovalInbox/Show', [
            'approval' => $this->inbox->row($approval),
            'doc' => $this->inbox->detail($approval),
            'stages' => $this->inbox->stages($approval),
            'canAct' => $this->inbox->canAct($user, $approval),
        ]);
    }

    public function act(Request $request, Approval $approval, ActOnApproval $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->can('approvalinbox.view'), 403);
        abort_unless(in_array($approval->document_type, ApprovalInboxQuery::DOCUMENT_TYPES, true), 404);

        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|required_if:action,reject|string|max:500',
        ], ['notes.required_if' => 'Alasan penolakan wajib diisi.']);

        try {
            $action->execute($approval->load('steps'), $data['action'], $data['notes'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $done = $data['action'] === 'approve' ? 'disetujui' : 'ditolak';

        return redirect()->route('approval-inbox.index', ['tab' => 'perlu'])
            ->with('success', "Dokumen #{$approval->id} {$done}.");
    }
}
