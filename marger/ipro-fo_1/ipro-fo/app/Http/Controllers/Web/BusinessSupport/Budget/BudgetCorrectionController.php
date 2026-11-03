<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Services\BudgetCorrectionService;
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
        abort_unless($request->user()?->can('budget.correction.view'), 403);
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
                'period' => self::labelPeriode($c),
                'old_amount' => (float) $c->old_amount, 'new_amount' => (float) $c->new_amount, 'status' => $c->status,
                'approval_url' => $c->approval_id ? route('approval-inbox.show', $c->approval_id) : null,
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
        abort_unless($request->user()?->can('budget.correction.create'), 403);
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

    /**
     * Simpan koreksi PER BULAN: isian = nilai baru tiap bulan; yang disimpan selisihnya terhadap
     * anggaran bulan itu saat ini. Masuk Approval Inbox (Accounting → GM), berlaku setelah disetujui.
     */
    public function store(Request $request, BudgetCorrectionService $service): RedirectResponse
    {
        abort_unless($request->user()?->can('budget.correction.create'), 403);
        $v = $request->validate([
            'budget_id'   => ['required', 'integer', 'exists:bs_budgets,id'],
            'corr_date'   => ['required', 'date'],
            'months'      => ['required', 'array'],
            'months.*'    => ['nullable', 'numeric', 'min:0'],
            'note'        => ['nullable', 'string', 'max:200'],
        ], [], ['months.*' => 'Nilai baru']);

        $budget = BsBudget::findOrFail($v['budget_id']);
        abort_unless($budget->status === 'approved', 422, 'Hanya anggaran yang sudah disetujui yang bisa dikoreksi.');
        $tambahan = [];
        foreach ($v['months'] as $m => $baru) {
            $m = (int) $m;
            if ($m < 1 || $m > 12 || $baru === null || $baru === '') {
                continue;
            }
            $delta = round((float) $baru - \App\Domain\BusinessSupport\Services\BudgetGuardService::anggaranBulan($budget, $m), 2);
            if ($delta != 0.0) {
                $tambahan[$m] = $delta;
            }
        }
        if ($tambahan === []) {
            return back()->withInput()->with('error', 'Belum ada bulan yang nilainya diubah.');
        }

        $c = DB::transaction(fn () => $service->ajukanManual($budget, $tambahan, $v['corr_date'], $v['note'] ?? null, $request->user()->id));

        return to_route('bs.budget.correction.index', ['status' => 'baru'])
            ->with('success', "Koreksi {$c->doc_no} diajukan ke Approval Inbox (Accounting → GM).");
    }

    /** Inbox approval batch koreksi. */
    public function approvals(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.correction.approve'), 403);

        $rows = BsBudgetCorrection::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('status', 'baru')->whereNull('approval_id')->orderBy('doc_no')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'doc_no' => $c->doc_no, 'date' => $c->corr_date?->format('Y-m-d'),
                'account' => $c->account?->code.' - '.$c->account?->name, 'cost_center' => $c->costCenter?->name ?? 'Semua',
                'period' => self::labelPeriode($c),
                'old_amount' => (float) $c->old_amount, 'new_amount' => (float) $c->new_amount,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Corrections/Approvals', [
            'rows' => $rows, 'canApprove' => (bool) $request->user()?->canAny(['budget.correction.approve', 'businesssupport.approval.act']),
            'summary' => ['count' => $rows->count()],
        ]);
    }

    /** Approve menerapkan Nilai Baru ke master budget; reject hanya menandai. */
    public function approveBatch(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['budget.correction.approve', 'businesssupport.approval.act']), 403);
        $v = $request->validate([
            'ids'      => ['required', 'array', 'min:1'],
            'ids.*'    => ['integer', 'exists:bs_budget_corrections,id'],
            'decision' => ['required', 'in:approve,reject'],
        ]);
        $approve = $v['decision'] === 'approve';

        // Koreksi yang diajukan dari invoice hutang diputuskan di Approval Inbox (approval_id) —
        // tidak ikut di sini, supaya satu koreksi tidak punya dua jalur keputusan.
        DB::transaction(function () use ($v, $request, $approve): void {
            $corrections = BsBudgetCorrection::whereIn('id', $v['ids'])->where('status', 'baru')->whereNull('approval_id')->get();
            foreach ($corrections as $c) {
                app(BudgetCorrectionService::class)->putuskan($c, $approve, $request->user()->id);
            }
        });

        return back()->with('success', $approve ? 'Koreksi disetujui & diterapkan.' : 'Koreksi ditolak.');
    }

    /** "Nov, Des 2026" untuk koreksi per bulan; koreksi lama "Jan s/d Des 2026". */
    public static function labelPeriode(BsBudgetCorrection $c): string
    {
        if (is_array($c->months) && $c->months !== []) {
            return collect(array_keys($c->tambahanBulan()))->map(fn ($m) => BsBudget::MONTHS[$m - 1])->implode(', ').' '.$c->year;
        }

        return BsBudget::MONTHS[($c->period_from ?: 1) - 1].' s/d '.BsBudget::MONTHS[($c->period_to ?: 12) - 1].' '.$c->year;
    }
}
