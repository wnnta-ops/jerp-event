<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\ApprovalInbox;

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Actions\SubmitMemoInternal;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Approval Inbox — tracking & keputusan approval bertingkat. Pengajuannya: Memo Urgent (form di
 * sini, dokumen MemoInternal) dan dari AP (TTD Berkas, Invoice Hutang, Koreksi Budget).
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
            'canSubmit' => $user->can('approvalinbox.submit'),
        ]);
    }

    /** Form Memo Urgent (dokumen MemoInternal → Accounting → Finance → GM → Direktur → MW / BA). */
    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('approvalinbox.submit'), 403);

        return Inertia::render('ApprovalInbox/Create', [
            'today' => now()->toDateString(),
            'departemen' => $this->pilihanDepartemen($request->user()),
            'semuaDept' => $request->user()->hasRole('SuperAdmin'),
        ]);
    }

    /**
     * Departemen yang boleh dipilih untuk Memo Urgent = cost center (unit kerja) karyawan yang
     * tertaut ke akun ini di Employee Center (utama lebih dulu); SuperAdmin boleh semua departemen.
     *
     * @return list<array{id:int, code:string, name:string}>
     */
    private function pilihanDepartemen(\App\Models\User $user): array
    {
        $q = \App\Domain\BusinessSupport\Models\BsCostCenter::query()->where('is_active', true);
        if (! $user->hasRole('SuperAdmin')) {
            $ids = app(\App\Domain\BusinessSupport\Services\CostCenterPengguna::class)->idsUntuk($user);
            if ($ids === []) {
                return [];
            }
            $q->whereIn('id', $ids)->orderByRaw('FIELD(id, '.implode(',', array_map('intval', $ids)).')');
        } else {
            $q->orderBy('code');
        }

        return $q->get(['id', 'code', 'name'])->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->all();
    }

    public function store(Request $request, SubmitMemoInternal $submit): RedirectResponse
    {
        abort_unless($request->user()?->can('approvalinbox.submit'), 403);

        // Departemen dipilih dari daftar milik user (SuperAdmin: semua) — server menyimpan NAMANYA.
        $dept = collect($this->pilihanDepartemen($request->user()));
        if ($dept->isEmpty()) {
            return back()->withInput()->with('error', 'Departemen Anda belum diatur — isi cost center Anda di Data Center › Employee Center, atau hubungi HRD.');
        }
        $data = $request->validate([
            'memo_date' => 'required|date',
            'cost_center_id' => ['required', 'integer', \Illuminate\Validation\Rule::in($dept->pluck('id')->all())],
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'amount' => 'required|numeric|min:1',
        ], [
            'cost_center_id.in' => 'Departemen ini bukan departemen Anda.',
            'amount.required' => 'Nominal wajib diisi.',
            'amount.min' => 'Nominal wajib diisi.',
        ], ['cost_center_id' => 'Departemen', 'amount' => 'Nominal']);
        $data['department'] = $dept->firstWhere('id', (int) $data['cost_center_id'])['name'];
        unset($data['cost_center_id']);

        try {
            $memo = $submit->execute($data);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('approval-inbox.show', $memo->approval_id)
            ->with('success', "Memo Urgent {$memo->memo_number} diajukan — menunggu Accounting.");
    }

    public function show(Request $request, Approval $approval): Response
    {
        $user = $request->user();
        abort_unless($user?->can('approvalinbox.view'), 403);
        abort_unless(in_array($approval->document_type, ApprovalInboxQuery::DOCUMENT_TYPES, true), 404);
        $approval->load('steps')->loadMorph('approvable', [
            \App\Domain\BusinessSupport\Models\BsApBerkasUpload::class => ['supplier:id,name', 'files.jenis:id,name'],
            \App\Domain\BusinessSupport\Models\BsApInvoice::class => ['supplier:id,name'],
            \App\Domain\BusinessSupport\Models\BsBudgetCorrection::class => ['account:id,code,name', 'costCenter:id,code,name', 'budget'],
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
