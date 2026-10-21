<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alokasi Budget (BO: Alokasi Budget Control / BudgetAlokasi.aspx, dok BC.AL/...).
 * Mengalokasikan sebagian anggaran master untuk periode; sisa = budget − alokasi disetujui.
 */
class BudgetAllocationController extends Controller
{
    /** Sisa budget yang masih bisa dialokasikan dari satu master budget. */
    public static function sisa(BsBudget $budget): float
    {
        $allocated = (float) BsBudgetAllocation::where('budget_id', $budget->id)
            ->whereIn('status', ['baru', 'approved'])->sum('amount');

        return round((float) $budget->amount - $allocated, 2);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.allocation.view'), 403);
        $year = (int) $request->integer('year', (int) now()->year);
        // Gate approval: default hanya alokasi yang SUDAH di-approve.
        $status = (string) $request->input('status', 'approved');

        $rows = BsBudgetAllocation::with(['account:id,code,name', 'costCenter:id,code,name', 'budget:id,doc_no'])
            ->where('year', $year)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'doc_no' => $a->doc_no, 'date' => $a->alloc_date?->format('Y-m-d'),
                'ref' => $a->budget?->doc_no, 'account' => $a->account?->code.' - '.$a->account?->name,
                'cost_center' => $a->costCenter?->name ?? 'Semua',
                'period' => BsBudget::MONTHS[($a->period_from ?: 1) - 1].' s/d '.BsBudget::MONTHS[($a->period_to ?: 12) - 1].' '.$a->year,
                'amount' => (float) $a->amount, 'total_budget' => (float) $a->total_budget, 'status' => $a->status,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Allocations/Index', [
            'year' => $year, 'rows' => $rows, 'statuses' => BsBudgetAllocation::STATUSES,
            'filterStatus' => $status,
            'pending' => BsBudgetAllocation::where('year', $year)->where('status', 'baru')->count(),
            'summary' => ['count' => $rows->count(), 'total' => $rows->sum('amount')],
        ]);
    }

    /** Wizard alokasi — daftar master budget approved dengan Total & Sisa. */
    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.allocation.create'), 403);
        $year = (int) $request->integer('year', (int) now()->year);

        $budgets = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('year', $year)->where('status', 'approved')->orderBy('doc_no')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'doc_no' => $b->doc_no,
                'account' => $b->account?->code.' - '.$b->account?->name,
                'account_id' => $b->account_id, 'cost_center_id' => $b->cost_center_id,
                'cost_center' => $b->costCenter?->name ?? 'Semua',
                'total' => (float) $b->amount, 'sisa' => self::sisa($b),
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Allocations/Create', [
            'year' => $year, 'budgets' => $budgets, 'monthLabels' => BsBudget::MONTHS,
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('budget.allocation.create'), 403);
        $v = $request->validate([
            'budget_id'    => ['required', 'integer', 'exists:bs_budgets,id'],
            'alloc_date'   => ['required', 'date'],
            'period_from'  => ['required', 'integer', 'min:1', 'max:12'],
            'period_to'    => ['required', 'integer', 'min:1', 'max:12'],
            'amount'       => ['required', 'numeric', 'min:0.01'],
            'notes'        => ['nullable', 'string', 'max:200'],
        ]);

        try {
            DB::transaction(function () use ($v, $request): void {
                $budget = BsBudget::lockForUpdate()->findOrFail($v['budget_id']);
                $sisa   = self::sisa($budget);
                $amount = round((float) $v['amount'], 2);
                if ($amount > $sisa) {
                    throw new BusinessRuleException('Nilai alokasi melebihi sisa budget (Rp '.number_format($sisa, 0, ',', '.').').', errorCode: 'ALLOC_OVER');
                }
                BsBudgetAllocation::create([
                    'tenant_id'    => tenant_id(),
                    'doc_no'       => BsBudgetAllocation::nextDocNo((int) $budget->year, $budget->project, $v['alloc_date']),
                    'year'         => $budget->year, 'project' => $budget->project, 'budget_id' => $budget->id,
                    'cost_center_id' => $budget->cost_center_id, 'account_id' => $budget->account_id,
                    'alloc_date'   => $v['alloc_date'], 'period_from' => $v['period_from'], 'period_to' => $v['period_to'],
                    'amount'       => $amount, 'total_budget' => (float) $budget->amount,
                    'status'       => 'baru', 'notes' => $v['notes'] ?? null, 'created_by' => $request->user()->id,
                ]);
            });
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('bs.budget.allocation.index')->with('success', 'Alokasi budget tercatat (menunggu approval).');
    }

    /** Inbox approval batch alokasi. */
    public function approvals(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.allocation.approve'), 403);

        $rows = BsBudgetAllocation::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('status', 'baru')->orderBy('doc_no')->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'doc_no' => $a->doc_no, 'year' => $a->year, 'date' => $a->alloc_date?->format('Y-m-d'),
                'account' => $a->account?->code.' - '.$a->account?->name, 'cost_center' => $a->costCenter?->name ?? 'Semua',
                'amount' => (float) $a->amount, 'total_budget' => (float) $a->total_budget,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Allocations/Approvals', [
            'rows' => $rows, 'canApprove' => (bool) $request->user()?->canAny(['budget.allocation.approve', 'businesssupport.approval.act']),
            'summary' => ['count' => $rows->count(), 'total' => $rows->sum('amount')],
        ]);
    }

    public function approveBatch(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['budget.allocation.approve', 'businesssupport.approval.act']), 403);
        $v = $request->validate([
            'ids'      => ['required', 'array', 'min:1'],
            'ids.*'    => ['integer', 'exists:bs_budget_allocations,id'],
            'decision' => ['required', 'in:approve,reject'],
        ]);
        $approve = $v['decision'] === 'approve';
        BsBudgetAllocation::whereIn('id', $v['ids'])->where('status', 'baru')->update([
            'status' => $approve ? 'approved' : 'rejected', 'approved_at' => now(), 'approved_by' => $request->user()->id,
        ]);

        return back()->with('success', $approve ? 'Alokasi disetujui.' : 'Alokasi ditolak.');
    }
}
