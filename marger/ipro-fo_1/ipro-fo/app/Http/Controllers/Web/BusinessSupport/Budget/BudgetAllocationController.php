<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Services\BudgetAllocationService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alokasi Budget (dok BC.AL/...): pindah anggaran dari budget A (COA lama) ke budget B (COA baru)
 * per bulan, langsung berlaku tanpa approval — lihat BudgetAllocationService. Baris lama (tanpa
 * budget tujuan) = alokasi model lama, tetap ditampilkan di daftar.
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

        $rows = BsBudgetAllocation::with(['account:id,code,name', 'costCenter:id,code,name', 'budget:id,doc_no', 'toBudget.account:id,code,name', 'toBudget.costCenter:id,name'])
            ->where('year', $year)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'doc_no' => $a->doc_no, 'date' => $a->alloc_date?->format('Y-m-d'),
                'ref' => $a->budget?->doc_no, 'account' => $a->account?->code.' - '.$a->account?->name,
                'cost_center' => $a->costCenter?->name ?? 'Semua',
                'period' => $a->months
                    ? collect(array_keys($a->months))->map(fn ($m) => BsBudget::MONTHS[(int) $m - 1])->implode(', ').' '.$a->year
                    : BsBudget::MONTHS[($a->period_from ?: 1) - 1].' s/d '.BsBudget::MONTHS[($a->period_to ?: 12) - 1].' '.$a->year,
                'to_ref' => $a->toBudget?->doc_no,
                'to_account' => $a->toBudget ? $a->toBudget->account?->code.' - '.$a->toBudget->account?->name : null,
                'to_cost_center' => $a->toBudget ? ($a->toBudget->costCenter?->name ?? 'Semua') : null,
                'dari_invoice' => $a->source_type === BsBudgetAllocation::SOURCE_INVOICE ? $a->source_id : null,
                'notes' => $a->notes,
                'amount' => (float) $a->amount, 'total_budget' => (float) $a->total_budget, 'status' => $a->status,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Allocations/Index', [
            'year' => $year, 'rows' => $rows, 'statuses' => BsBudgetAllocation::STATUSES,
            'filterStatus' => $status,
            'pending' => BsBudgetAllocation::where('year', $year)->where('status', 'baru')->count(),
            'summary' => ['count' => $rows->count(), 'total' => $rows->sum('amount')],
        ]);
    }

    /** Form alokasi — master budget approved; angka per bulan diambil dari bs.budget.bulan. */
    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.allocation.create'), 403);
        $year = (int) $request->integer('year', (int) now()->year);

        $budgets = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('year', $year)->where('status', 'approved')->orderBy('doc_no')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'doc_no' => $b->doc_no,
                'account' => $b->account?->code.' - '.$b->account?->name,
                'cost_center' => $b->costCenter?->name ?? 'Semua', 'amount' => (float) $b->amount,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Allocations/Create', [
            'year' => $year, 'budgets' => $budgets, 'monthLabels' => BsBudget::MONTHS,
        ]);
    }

    public function store(Request $request, BudgetAllocationService $service): RedirectResponse
    {
        abort_unless($request->user()?->can('budget.allocation.create'), 403);
        $v = $request->validate([
            'budget_id'    => ['required', 'integer', 'exists:bs_budgets,id'],
            'to_budget_id' => ['required', 'integer', 'exists:bs_budgets,id', 'different:budget_id'],
            'alloc_date'   => ['required', 'date'],
            'months'       => ['required', 'array'],
            'months.*'     => ['nullable', 'numeric', 'min:0'],
            'notes'        => ['nullable', 'string', 'max:200'],
        ], ['to_budget_id.different' => 'Budget tujuan tidak boleh sama dengan budget asal.'], ['budget_id' => 'Budget asal', 'to_budget_id' => 'Budget tujuan']);

        try {
            $a = DB::transaction(fn () => $service->pindah((int) $v['budget_id'], (int) $v['to_budget_id'],
                array_map(fn ($x) => (float) ($x ?? 0), $v['months']), $v['alloc_date'], $v['notes'] ?? null, $request->user()->id));
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return to_route('bs.budget.allocation.index')->with('success', "Alokasi {$a->doc_no} tersimpan — anggaran sudah dipindah.");
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
