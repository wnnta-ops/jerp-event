<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Koreksi Budget (BO: Koreksi Budget Control / BudgetMutasi1.aspx, dok BC.MA/...).
 * Wizard: pilih COA/budget → input nilai baru → approval. Nilai baru berlaku setelah disetujui.
 */
class BudgetCorrectionController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('budget'), 403);
        $year = (int) $request->integer('year', (int) now()->year);
        // Gate approval: default hanya koreksi yang SUDAH di-approve.
        $status = (string) $request->input('status', 'approved');

        $rows = BsBudgetCorrection::with(['account:id,code,name', 'costCenter:id,code,name', 'budget:id,doc_no'])
            ->where('year', $year)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'doc_no' => $c->doc_no, 'date' => $c->corr_date?->format('Y-m-d'),
                'ref' => $c->budget?->doc_no, 'account' => $c->account?->code.' - '.$c->account?->name,
                'cost_center' => $c->costCenter?->name ?? 'Semua',
                'period' => BsBudget::MONTHS[($c->period_from ?: 1) - 1].' s/d '.BsBudget::MONTHS[($c->period_to ?: 12) - 1].' '.$c->year,
                'old_amount' => (float) $c->old_amount, 'new_amount' => (float) $c->new_amount, 'status' => $c->status,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Corrections/Index', [
            'year' => $year, 'rows' => $rows, 'statuses' => BsBudgetCorrection::STATUSES,
            'filterStatus' => $status,
            'pending' => BsBudgetCorrection::where('year', $year)->where('status', 'baru')->count(),
            'summary' => ['count' => $rows->count()],
        ]);
    }

    /** Wizard koreksi — daftar master budget approved untuk dipilih. */
    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('budget'), 403);
        $year = (int) $request->integer('year', (int) now()->year);

        $budgets = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('year', $year)->where('status', 'approved')->orderBy('doc_no')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'doc_no' => $b->doc_no,
                'account' => $b->account?->code.' - '.$b->account?->name,
                'cost_center' => $b->costCenter?->name ?? 'Semua', 'amount' => (float) $b->amount,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Corrections/Create', [
            'year' => $year, 'budgets' => $budgets, 'monthLabels' => BsBudget::MONTHS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('budget'), 403);
        $v = $request->validate([
            'budget_id'   => ['required', 'integer', 'exists:bs_budgets,id'],
            'corr_date'   => ['required', 'date'],
            'period_from' => ['required', 'integer', 'min:1', 'max:12'],
            'period_to'   => ['required', 'integer', 'min:1', 'max:12'],
            'new_amount'  => ['required', 'numeric', 'min:0'],
            'note'        => ['nullable', 'string', 'max:200'],
        ]);

        $budget = BsBudget::findOrFail($v['budget_id']);
        BsBudgetCorrection::create([
            'tenant_id'   => tenant_id(),
            'doc_no'      => BsBudgetCorrection::nextDocNo((int) $budget->year, $budget->project, $v['corr_date']),
            'year'        => $budget->year, 'project' => $budget->project, 'budget_id' => $budget->id,
            'cost_center_id' => $budget->cost_center_id, 'account_id' => $budget->account_id,
            'corr_date'   => $v['corr_date'], 'period_from' => $v['period_from'], 'period_to' => $v['period_to'],
            'old_amount'  => (float) $budget->amount, 'new_amount' => round((float) $v['new_amount'], 2),
            'status'      => 'baru', 'note' => $v['note'] ?? null, 'created_by' => $request->user()->id,
        ]);

        return to_route('bs.budget.correction.index')->with('success', 'Koreksi budget tercatat (menunggu approval).');
    }

    /** Inbox approval batch koreksi. */
    public function approvals(Request $request): Response
    {
        abort_unless($request->user()?->canModule('budget'), 403);

        $rows = BsBudgetCorrection::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('status', 'baru')->orderBy('doc_no')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'doc_no' => $c->doc_no, 'date' => $c->corr_date?->format('Y-m-d'),
                'account' => $c->account?->code.' - '.$c->account?->name, 'cost_center' => $c->costCenter?->name ?? 'Semua',
                'period' => BsBudget::MONTHS[($c->period_from ?: 1) - 1].' s/d '.BsBudget::MONTHS[($c->period_to ?: 12) - 1].' '.$c->year,
                'old_amount' => (float) $c->old_amount, 'new_amount' => (float) $c->new_amount,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Corrections/Approvals', [
            'rows' => $rows, 'canApprove' => (bool) $request->user()?->can('businesssupport.approval.act'),
            'summary' => ['count' => $rows->count()],
        ]);
    }

    /** Approve menerapkan Nilai Baru ke master budget; reject hanya menandai. */
    public function approveBatch(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate([
            'ids'      => ['required', 'array', 'min:1'],
            'ids.*'    => ['integer', 'exists:bs_budget_corrections,id'],
            'decision' => ['required', 'in:approve,reject'],
        ]);
        $approve = $v['decision'] === 'approve';

        DB::transaction(function () use ($v, $request, $approve): void {
            $corrections = BsBudgetCorrection::whereIn('id', $v['ids'])->where('status', 'baru')->get();
            foreach ($corrections as $c) {
                if ($approve && $c->budget_id) {
                    $budget = BsBudget::find($c->budget_id);
                    if ($budget) {
                        $new   = round((float) $c->new_amount, 2);
                        $delta = $new - (float) $budget->amount;
                        // Sebar selisih merata pada periode koreksi (kompat tampilan bulanan).
                        $from  = max(1, (int) $c->period_from);
                        $to    = min(12, (int) $c->period_to);
                        $span  = max(1, $to - $from + 1);
                        $per   = round($delta / $span, 2);
                        for ($m = $from; $m <= $to; $m++) {
                            $budget->{'m'.$m} = round((float) ($budget->{'m'.$m} ?? 0) + $per, 2);
                        }
                        $budget->amount = $new;
                        $budget->save();
                    }
                }
                $c->update(['status' => $approve ? 'approved' : 'rejected', 'approved_at' => now(), 'approved_by' => $request->user()->id]);
            }
        });

        return back()->with('success', $approve ? 'Koreksi disetujui & diterapkan.' : 'Koreksi ditolak.');
    }
}
