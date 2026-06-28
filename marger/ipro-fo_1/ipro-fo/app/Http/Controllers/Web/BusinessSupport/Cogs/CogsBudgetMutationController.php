<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Cogs;

use App\Domain\BusinessSupport\Models\BsCogsBudget;
use App\Domain\BusinessSupport\Models\BsCogsBudgetMutation;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** COGS Mutasi Budget (transfer/koreksi) + Approval (jurnal tidak ada; hanya geser alokasi). */
class CogsBudgetMutationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $year = (int) $request->integer('year', (int) now()->year);

        $rows = BsCogsBudgetMutation::with(['fromBudget.category:id,code,name', 'toBudget.category:id,code,name'])
            ->where('year', $year)->orderByDesc('id')->get()
            ->map(fn ($m) => [
                'id' => $m->id, 'doc_no' => $m->doc_no, 'date' => $m->mutation_date?->format('Y-m-d'), 'kind' => $m->kind,
                'from' => $m->fromBudget?->category?->code, 'to' => $m->toBudget?->category?->code,
                'amount' => (float) $m->amount, 'status' => $m->status, 'note' => $m->note,
            ])->values();

        return Inertia::render('BusinessSupport/Cogs/Mutation/Index', [
            'year' => $year, 'rows' => $rows, 'statuses' => BsCogsBudgetMutation::STATUSES,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $year = (int) $request->integer('year', (int) now()->year);

        $budgets = BsCogsBudget::with(['category:id,code,name', 'location:id,name'])
            ->where('year', $year)->orderBy('project_id')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'project_id' => $b->project_id,
                'label' => ($b->category?->code.' '.$b->category?->name).' · '.($b->location?->name ?? 'umum').' (Rp '.number_format((float) $b->amount, 0, ',', '.').')',
                'amount' => (float) $b->amount,
            ])->values();

        return Inertia::render('BusinessSupport/Cogs/Mutation/Create', [
            'year' => $year, 'budgets' => $budgets, 'projects' => Project::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $v = $request->validate([
            'project_id'     => ['required', 'integer'],
            'kind'           => ['required', 'in:transfer,koreksi'],
            'mutation_date'  => ['required', 'date'],
            'from_budget_id' => ['nullable', 'integer', 'exists:bs_cogs_budgets,id'],
            'to_budget_id'   => ['required', 'integer', 'exists:bs_cogs_budgets,id'],
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'note'           => ['nullable', 'string', 'max:200'],
        ]);
        $year = (int) date('Y', strtotime($v['mutation_date']));
        BsCogsBudgetMutation::create(array_merge($v, [
            'tenant_id' => tenant_id(), 'year' => $year, 'status' => 'baru',
            'doc_no'    => 'CG.MUT/'.date('y', strtotime($v['mutation_date'])).'/'.str_pad((string) (BsCogsBudgetMutation::whereYear('mutation_date', $year)->count() + 1), 5, '0', STR_PAD_LEFT),
            'created_by' => $request->user()->id,
        ]));

        return to_route('bs.cogs.mutation.index')->with('success', 'Mutasi budget diajukan (menunggu approval).');
    }

    public function approvals(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        $rows = BsCogsBudgetMutation::with(['fromBudget.category:id,code', 'toBudget.category:id,code'])
            ->where('status', 'baru')->orderBy('id')->get()
            ->map(fn ($m) => [
                'id' => $m->id, 'doc_no' => $m->doc_no, 'date' => $m->mutation_date?->format('Y-m-d'), 'kind' => $m->kind,
                'from' => $m->fromBudget?->category?->code ?? '—', 'to' => $m->toBudget?->category?->code ?? '—',
                'amount' => (float) $m->amount, 'note' => $m->note,
            ])->values();

        return Inertia::render('BusinessSupport/Cogs/Mutation/Approvals', [
            'rows' => $rows, 'canApprove' => (bool) $request->user()?->can('businesssupport.approval.act'),
            'summary' => ['count' => $rows->count(), 'total' => $rows->sum('amount')],
        ]);
    }

    public function approveBatch(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'decision' => ['required', 'in:approve,reject']]);
        $approve = $v['decision'] === 'approve';

        $ok = 0;
        DB::transaction(function () use ($v, $request, $approve, &$ok): void {
            foreach (BsCogsBudgetMutation::whereIn('id', $v['ids'])->where('status', 'baru')->get() as $m) {
                if ($approve) {
                    $to = $m->to_budget_id ? BsCogsBudget::find($m->to_budget_id) : null;
                    $from = $m->from_budget_id ? BsCogsBudget::find($m->from_budget_id) : null;
                    $amt = (float) $m->amount;
                    if ($m->kind === 'transfer' && $from && $to) {
                        if ((float) $from->amount < $amt) {
                            continue; // dana asal tak cukup → lewati
                        }
                        $from->update(['amount' => round((float) $from->amount - $amt, 2)]);
                        $to->update(['amount' => round((float) $to->amount + $amt, 2)]);
                    } elseif ($to) {
                        $to->update(['amount' => round((float) $to->amount + $amt, 2)]); // koreksi (+)
                    }
                }
                $m->update(['status' => $approve ? 'approved' : 'rejected', 'approved_at' => now(), 'approved_by' => $request->user()->id]);
                $ok++;
            }
        });

        return back()->with('success', $approve ? "$ok mutasi disetujui & alokasi diperbarui." : "$ok mutasi ditolak.");
    }
}
