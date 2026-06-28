<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Approval;

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('approval.view'), 403);

        $rows = Approval::query()
            ->with(['steps', 'approvable'])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('document_type'), fn ($q, $d) => $q->where('document_type', $d))
            ->when($request->input('tab') === 'mine',
                fn ($q) => $q->whereHas('steps', fn ($q) => $q->where('status', 'pending'))
            )
            ->latest()
            ->paginate(50);

        return Inertia::render('Approval/Index', [
            'approvals' => $rows,
            'filters'   => $request->only(['status', 'document_type', 'tab']),
        ]);
    }

    public function show(Approval $approval): Response
    {
        abort_unless(auth()->user()?->can('approval.view'), 403);

        return Inertia::render('Approval/Show', [
            'approval' => $approval->load(['steps.actions', 'approvable']),
        ]);
    }

    public function act(Request $request, Approval $approval, ActOnApproval $action): RedirectResponse
    {
        abort_unless(auth()->user()?->can('approval.act'), 403);

        $data = $request->validate([
            'action' => 'required|in:approve,reject,delegate',
            'notes'  => 'nullable|string|max:500',
        ]);

        $action->execute($approval, $data['action'], $data['notes'] ?? null);

        return redirect()->route('approvals.index')
            ->with('success', "Approval #{$approval->id} {$data['action']}d.");
    }
}
