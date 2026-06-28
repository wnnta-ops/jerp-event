<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\BusinessSupport\Models\BsWorkOrderProgressMutation;
use App\Domain\BusinessSupport\Services\ContractorService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mutasi Progress Bangunan (reklas CIP) + Approval. Registrasi = status 'baru' (tanpa jurnal);
 * jurnal reklas terbit saat di-approve.
 */
class MutationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $rows = BsWorkOrderProgressMutation::query()
            ->with(['workOrder:id,spk_number,title', 'toAccount:id,code,name', 'fromAccount:id,code,name', 'toCostCenter:id,code,name'])
            ->latest('mutation_date')->latest('id')->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Contractor/Mutation/Index', [
            'rows'     => $rows,
            'statuses' => BsWorkOrderProgressMutation::STATUSES,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $spks = BsWorkOrder::with(['supplier:id,name', 'constructionAccount:id,code,name', 'costCenter:id,code,name'])
            ->where('status', 'active')->orderByDesc('spk_date')->limit(500)->get()
            ->map(fn ($w) => [
                'id' => $w->id, 'spk_number' => $w->spk_number, 'title' => $w->title, 'supplier' => $w->supplier?->name,
                'from_account' => $w->constructionAccount?->code.' - '.$w->constructionAccount?->name,
                'cost_center' => $w->costCenter?->name ?? '—',
                'available' => round((float) $w->progress_value - (float) $w->progress_mutated, 2),
            ])->values();

        return Inertia::render('BusinessSupport/Contractor/Mutation/Create', [
            'spks'        => $spks,
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where(fn ($q) => $q->where('code', 'like', '10104%')->orWhere('type', 'expense'))
                ->orderBy('code')->get(['id', 'code', 'name']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $v = $request->validate([
            'work_order_id'     => ['required', 'integer', 'exists:bs_work_orders,id'],
            'mutation_date'     => ['required', 'date'],
            'amount'            => ['required', 'numeric', 'min:0.01'],
            'to_account_id'     => ['required', 'integer', 'exists:bs_accounts,id'],
            'to_cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'note'              => ['nullable', 'string', 'max:255'],
        ]);
        $wo = BsWorkOrder::findOrFail($v['work_order_id']);
        try {
            $svc->registerMutation($wo, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.mutation.index')->with('success', 'Mutasi progress diajukan (menunggu approval).');
    }

    public function approvals(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $rows = BsWorkOrderProgressMutation::query()
            ->with(['workOrder:id,spk_number,title', 'fromAccount:id,code,name', 'toAccount:id,code,name', 'toCostCenter:id,code,name'])
            ->where('status', 'baru')->orderBy('mutation_date')->orderBy('id')->get()
            ->map(fn ($m) => [
                'id' => $m->id, 'spk' => $m->workOrder?->spk_number, 'date' => $m->mutation_date?->format('Y-m-d'),
                'from' => $m->fromAccount?->code, 'to' => $m->toAccount?->code,
                'cost_center' => $m->toCostCenter?->name ?? '—', 'amount' => (float) $m->amount, 'note' => $m->note,
            ])->values();

        return Inertia::render('BusinessSupport/Contractor/Mutation/Approvals', [
            'rows'       => $rows,
            'canApprove' => (bool) $request->user()?->can('businesssupport.approval.act'),
            'summary'    => ['count' => $rows->count(), 'total' => $rows->sum('amount')],
        ]);
    }

    public function approveBatch(Request $request, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate([
            'ids'      => ['required', 'array', 'min:1'],
            'ids.*'    => ['integer', 'exists:bs_work_order_progress_mutations,id'],
            'decision' => ['required', 'in:approve,reject'],
        ]);

        $ok = 0;
        foreach (BsWorkOrderProgressMutation::whereIn('id', $v['ids'])->where('status', 'baru')->get() as $m) {
            try {
                $v['decision'] === 'approve' ? $svc->approveMutation($m, $request->user()->id) : $svc->rejectMutation($m, $request->user()->id);
                $ok++;
            } catch (BusinessRuleException $e) {
                // lewati yang gagal (mis. progress tak cukup), lanjut yang lain
            }
        }

        return back()->with('success', ($v['decision'] === 'approve' ? "$ok mutasi disetujui & jurnal diposting." : "$ok mutasi ditolak."));
    }
}
